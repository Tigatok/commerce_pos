<?php

namespace Drupal\commerce_pos_reports;

use Drupal\commerce_pos\Entity\Register;
use Drupal\Core\Database\Connection;
use Drupal\Core\Messenger\Messenger;

/**
 * This service provides assistance for generating and saving reports.
 */
class ReportGenerator {

  protected $connection;

  protected $messenger;

  /**
   * The ReportGenerator constructor.
   *
   * @param \Drupal\Core\Database\Connection $connection
   *   The database connection.
   * @param \Drupal\Core\Messenger\Messenger $messenger
   *   The message service.
   */
  public function __construct(Connection $connection, Messenger $messenger) {
    $this->connection = $connection;
    $this->messenger = $messenger;
  }

  /**
   * Returns an exisiting report.
   *
   * @param $date
   *   The day of the report to get.
   * @param $register_id
   *   The register of the report.
   *
   * @return mixed
   *   The reports to return.
   */
  public function getReportsByDay($date, $register_id) {
    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('register_id', $register_id, '=')
      ->condition('date', strtotime($date), '=');
    $results = $query->execute()->fetchAll();

    if (empty($results)) {
      return NULL;
    }

    $reports = [];

    foreach ($results as $result) {
      if ($result->closed_timestamp != 0) {
        $timestamp = date('h:i:s A', $result->open_timestamp) . ' - ' . date('h:i:s A', $result->closed_timestamp);
        $reports[$result->closed_timestamp] = $timestamp;
      }
    }
    if (empty($reports)) {
      return NULL;
    }

    return $reports;
  }

  /**
   * Returns a specific report by version.
   *
   * @param $closed_timestamp
   *   The version of the report to get.
   * @param $register_id
   *   The register id for report to generate.
   *
   * @return mixed
   *   The results.
   */
  public function getReportsByVersionTimestamp($closed_timestamp, $register_id) {
    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('register_id', $register_id, '=')
      ->condition('closed_timestamp', $closed_timestamp, '=');
    $report = $query->execute()->fetchAssoc();

    if (!empty($report)) {
      $report['data'] = unserialize($report['data']);

      return $report;
    }

    return NULL;
  }

  /**
   * Checks if a report already exists, used to determine update or insert.
   *
   * @param string $date
   *   A strtotime compatible date, will search this date exactly.
   * @param int $register_id
   *   Id of the register to load the report for.
   *
   * @return bool
   *   True if the report exists, false if it doesn't.
   */
  public function reportExists($date, $register_id) {
    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('register_id', $register_id, '=')
      ->condition('date', strtotime($date), '=');
    $result = $query->execute()->fetchAssoc();

    return !empty($result);
  }

  /**
   * Returns the latest report for the day.
   */
  public function getLatestReportForDay($date, $register_id) {
    $beginning_of_day = $date;
    $end_of_day = strtotime("tomorrow", strtotime($beginning_of_day)) - 1;

    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('register_id', $register_id, '=')
      ->condition('date', strtotime($beginning_of_day), '>=')
      ->condition('date', $end_of_day, '<');
    $result = $query->execute()->fetchAll();
    $latest = NULL;
    foreach ($result as $row) {
      if ($latest == NULL) {
        $latest = $row;
      }
      if ($row->state < $latest->state) {
        $latest = $row;
      }
    }

    $latest = json_decode(json_encode($latest), TRUE);
    $latest['data'] = unserialize($latest['data']);
    return $latest;
  }

  /**
   * Save a report and set it's state to either open or closed.
   *
   * @param \Drupal\commerce_pos\Entity\Register $register
   *   The register the report is for.
   * @param $date
   *   The date of the report.
   * @param array $data
   *   The data for the report.
   * @param bool $state
   *   Whether we close the report or not.
   */
  public function saveReport(Register $register, $date, array $data) {
    $register_id = $register->id();

    // Remove the register_id and date as keys from the declared values before
    // inserting because we don't need it. It was just added due to the default
    // values not changing via ajax callback issue.
    foreach ($data as $payment_id => $values) {
      $data[$payment_id]['declared'] = $values['declared'][$register_id][$date];
      unset($values['declared'][$register_id][$date]);

      if (isset($data[$payment_id]['cash_deposit'])) {
        $data[$payment_id]['cash_deposit'] = $values['cash_deposit'][$register_id][$date];
        unset($values['cash_deposit'][$register_id][$date]);
      }
    }

    $serial_data = serialize($data);

    $state = NULL;
    $version_timestamp = NULL;

    // If the register is closed make sure we keep the report closed.
    if (!$register->isOpen()) {
      $state = TRUE;
      $version_timestamp = strtotime(date('h:i:s A', time()));
    }

    // Before we insert the values into the db, determine if a report for this
    // date already exists so we know to update or insert.
    $exists = $this->reportExists($date, $register_id);
    $latest_report = $this->getLatestReportForDay($date, $register_id);
    if (!isset($latest_report['state'])) {
      $latest_report_state = NULL;
    }
    else {
      $latest_report_state = $latest_report['state'];
    }
    if ($exists && ($latest_report_state == NULL || $latest_report_state == 0)) {
      $this->updateReport($date, $register_id, $serial_data, $state);
    }
    else {
      $this->createReport($date, $register_id, $serial_data, $state);
    }

    $this->messenger->addMessage(t('Successfully saved the declared values for register @register.', [
      '@register' => $register->label(),
    ]));
  }

  /**
   * Updates an exisiting report.
   *
   * @param $date
   *   The date of the report to update.
   * @param $register_id
   *   The register id of the report.
   * @param $serial_data
   *   The data to update.
   * @param bool $state
   *   The state of the report. 0 if open, 1 if closed.
   *   You need elevated permissions to update a closed report.
   *   Once a report is closed, it cannot be opened again.
   * @param null $open_timestamp
   * @param null $closed_timestamp
   *
   * @return bool
   */
  public function updateReport($date, $register_id, $serial_data, $state = FALSE, $open_timestamp = NULL, $closed_timestamp = NULL) {
    $query = $this->connection;
    // If State, then we are setting an open report to closed, or updating a closed report.
    if ($state) {
      $query = $query->update('commerce_pos_report_declared_data')
        ->condition('register_id', $register_id, '=')
        ->condition('open_timestamp', $open_timestamp, '=')
        ->fields([
          'data' => $serial_data,
          'state' => (int) $state,
          'closed_timestamp' => $closed_timestamp,
        ]);
    }
    // If the report is open, we are just updating a report.
    else {
      $query = $query->update('commerce_pos_report_declared_data')
        ->condition('register_id', $register_id, '=')
        ->condition('state', $open_timestamp, '=')
        ->fields([
          'data' => $serial_data,
        ]);
    }

    try {
      $query->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Generates a new report.
   *
   * @param $date
   *   The day of the report.
   * @param $register_id
   *   The register the report is targeting.
   * @param $serial_data
   *   The serialized data for the report.
   * @param bool $state
   *   The state of the report, 0 if open, 1 if closed.
   * @param null $open_timestamp
   *   The open timestamp of the report.
   * @param null $closed_timestamp
   *   The closed timestmap of the report.
   *
   * @return bool
   */
  public function createReport($date, $register_id, $serial_data = NULL, $state = TRUE, $open_timestamp = NULL, $closed_timestamp = NULL) {
    $query = $this->connection;
    if ($open_timestamp == NULL) {
      $open_timestamp = strtotime(date('h:i:s', time()));
    }
    // If close and save was selected.
    if ($state == TRUE) {
      if ($closed_timestamp == NULL) {
        $closed_timestamp = strtotime(date('h:i:s', time()));
      }
      $query = $query->insert('commerce_pos_report_declared_data')
        ->fields([
          'register_id' => $register_id,
          'date' => strtotime($date),
          'data' => $serial_data,
          'open_timestamp' => $open_timestamp,
          'closed_timestamp' => $closed_timestamp,
          'state' => (int) $state,
        ]);
    }
    else {
      $query = $query->insert('commerce_pos_report_declared_data')
        ->fields([
          'register_id' => $register_id,
          'date' => strtotime($date),
          'data' => $serial_data,
          'open_timestamp' => $open_timestamp,
          'closed_timestamp' => $closed_timestamp,
          'state' => (int) $state,
        ]);
    }
    try {
      $query->execute();
      return TRUE;
    }
    catch (\Exception $e) {
      return FALSE;
    }
  }

  /**
   * Returns an array of closed reports.
   *
   * @param $date
   *   The date to grab the reports for.
   * @param $register_id
   *   The register ID to target.
   *
   * @return mixed
   *   The closed reports.
   */
  public function getClosedReports($date, $register_id) {
    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('date', strtotime($date), '=')
      ->condition('register_id', $register_id, '=')
      ->condition('state', 1, '=');
    $results = $query->execute()->fetchAll();
    $results = json_decode(json_encode($results), TRUE);
    foreach ($results as $result_key => $result_value) {
      $results[$result_key]['data'] = unserialize($result_value['data']);
    }
    return $results;
  }

  /**
   * Get the versions of a report on a given day.
   *
   * @param $date
   *   The day to get the versions of reports for.
   * @param $register_id
   *   The register id.
   *
   * @return array
   *   The report versions.
   */
  public function getReportVersions($date, $register_id) {
    $reports = $this->getClosedReports($date, $register_id);
    $report_versions = [];
    foreach ($reports as $report) {
      $report_versions[$report['version_timestamp']] = date('h:i:s A', $report['version_timestamp']);
    }
    return $report_versions;
  }

}
