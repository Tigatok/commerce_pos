<?php

namespace Drupal\Tests\commerce_pos_reports\Kernel;

use Drupal\commerce_pos\Entity\Register;
use Drupal\commerce_price\Price;
use Drupal\Tests\commerce\Kernel\CommerceKernelTestBase;

/**
 * Tests the ReportGenerator service.
 */
class ReportGeneratorKernelTest extends CommerceKernelTestBase {

  public static $modules = [
    'search_api_db',
    'commerce_pos',
    'commerce_pos_reports',
    'commerce_store',
    'commerce_price',
    'commerce_product',
    'commerce_payment',
    'commerce_order',
    'entity_reference_revisions',
    'commerce_tax',
    'profile',
    'state_machine',
    'path',
    'telephone',
  ];

  protected $register;

  protected $connection;

  /**
   * @var \Drupal\commerce_pos_reports\ReportGenerator*/
  protected $reportGenerator;

  /**
   * {@inheritdoc}
   */
  protected function getAdministratorPermissions() {
    return [
      'view the administration theme',
      'access administration pages',
      'access commerce administration pages',
      'administer commerce_currency',
      'administer commerce_store',
      'administer commerce_store_type',
      'access commerce pos administration pages',
      'access commerce pos reports',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function setUp() {
    parent::setUp();

    $this->installEntitySchema('commerce_pos_register');
    $this->installEntitySchema('commerce_order');
    $this->installEntitySchema('commerce_payment');
    $this->installEntitySchema('commerce_product');
    $this->installEntitySchema('commerce_product_variation');
    $this->installSchema('commerce_pos_reports', 'commerce_pos_report_declared_data');
//    $this->installSchema('commerce_order', 'commerce_pos_report_declared_data');
    $this->installEntitySchema('commerce_order_item');
    $this->installConfig(['commerce', 'path', 'commerce_product', 'commerce_order', 'commerce_pos']);

    $this->register = Register::create([
      'store_id' => $this->store->id(),
      'name' => 'Test register',
      'default_float' => new Price('100.00', 'USD'),
    ]);

    $this->register->open();
    $this->register->setOpeningFloat($this->register->getDefaultFloat());
    $this->register->save();

    $this->connection = \Drupal::service('database');

    $this->reportGenerator = \Drupal::service('commerce_pos_reports.report_generator');
  }

  /**
   * Tests the creation of a report.
   */
  public function testCreateReport() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $report = $this->reportGenerator->createReport($date, $this->register->id(), $serial_data);
    $this->assertTrue($report);
  }

  /**
   * Tests updating a report.
   */
  public function testUpdateReport() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, FALSE);

    $update_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"20";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->updateReport($date, $this->register->id(), $update_data);
    $query = $this->connection;
    $query = $query->select('commerce_pos_report_declared_data', 't')
      ->fields('t')
      ->condition('date', strtotime($date), '=')
      ->condition('register_id', $this->register->id(), '=');
    $results = $query->execute()->fetchAssoc();
    $this->assertEquals($update_data, $results['data']);
  }

  /**
   * Tests that a report exists.
   */
  public function testReportExists() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data);

    $exists = $this->reportGenerator->reportExists($date, $this->register->id());
    $this->assertTrue($exists);

    $date = date('Y-m-d', strtotime('tomorrow'));
    $exists = $this->reportGenerator->reportExists($date, $this->register->id());
    $this->assertFalse($exists);
  }

  /**
   * Tests getting the latest report for the day.
   */
  public function testGetLatestReportForDay() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, TRUE);

    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"15";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, FALSE);

    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, TRUE);

    $latest_report = $this->reportGenerator->getLatestReportForDay($date, $this->register->id());
    $this->assertEquals('15', $latest_report['data']['pos_cash']['declared']);
  }

  /**
   * Tests saving a report.
   */
  public function testSaveReport() {
    $reg_id = $this->register->id();
    $date = date('Y-m-d');

    $data = [
      'pos_cash' => [
        'declared' => [
          $reg_id => [
            $date => '15',
          ],
        ],
        'cash_deposit' => [
          $reg_id => [
            $date => '0',
          ],
        ],
      ],
      'pos_credit' => [
        'declared' => [
          $reg_id => [
            $date => '0',
          ],
        ],
      ],
      'pos_debit' => [
        'declared' => [
          $reg_id => [
            $date => '0',
          ],
        ],
      ],
      'pos_gift_card' => [
        'declared' => [
          $reg_id => [
            $date => '0',
          ],
        ],
      ],
    ];

    $this->reportGenerator->saveReport($this->register, $date, $data);
    $this->assertNotNull($this->reportGenerator->getLatestReportForDay($date, $this->register->id()));
  }

  /**
   * Tests that we can get closed reports.
   */
  public function testGetClosedReports() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, TRUE);

    // Check that we get reports.
    $closed_reports = $this->reportGenerator->getClosedReports($date, $this->register->id());
    $this->assertNotEmpty($closed_reports);

    // Check that we grabbed the right report.
    $this->assertEquals('10', $closed_reports[0]['data']['pos_cash']['declared']);

    // Assert that the report is closed.
    $this->assertTrue($closed_reports[0]['state']);
  }

  /**
   * Test report versions. Want to do more than 1 but kernel test fast.
   */
  public function testGetReportVersions() {
    $date = date('Y-m-d');
    $serial_data = 'a:4:{s:8:"pos_cash";a:2:{s:8:"declared";s:2:"10";s:12:"cash_deposit";s:1:"0";}s:10:"pos_credit";a:1:{s:8:"declared";s:1:"0";}s:9:"pos_debit";a:1:{s:8:"declared";s:1:"0";}s:13:"pos_gift_card";a:1:{s:8:"declared";s:1:"0";}}';
    $this->reportGenerator->createReport($date, $this->register->id(), $serial_data, TRUE);

    $report_versions = $this->reportGenerator->getReportVersions($date, $this->register->id());
    $this->assertTrue(count($report_versions) == 1);
  }

}
