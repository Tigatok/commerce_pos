<?php

namespace Drupal\commerce_pos_reports\Form;

use Drupal\commerce_pos\Entity\Register;
use Drupal\commerce_pos_reports\Ajax\PrintEodReport;
use Drupal\commerce_price\Entity\Currency;
use Drupal\Core\Ajax\AjaxResponse;
use Drupal\Core\Ajax\PrependCommand;
use Drupal\Core\Form\FormBase;
use Drupal\Core\Form\FormStateInterface;
use Drupal\Core\Url;

/**
 *
 */
class PreviousReportsForm extends FormBase {

  /**
   * Returns a unique string identifying the form.
   *
   * @return string
   *   The unique string identifying the form.
   */
  public function getFormId() {
    return 'commece_pos_reports_previous_reports';
  }

  /**
   * Form constructor.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   *
   * @return array
   *   The form structure.
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    // Setup form.
    $form['#attached']['library'][] = 'commerce_pos_reports/reports';
    $form['#attached']['library'][] = 'commerce_pos/jQuery.print';

    $can_update = $this->currentUser()->hasPermission('update commerce pos closed reports');

    $handler = \Drupal::service('module_handler');
    $path = $handler->getModule('commerce_pos_reports')->getPath();
    $js_settings = [
      'commercePosReports' => [
        'cssUrl' => Url::fromUserInput('/' . $path . '/css/commerce_pos_reports_receipt.css', [
          'absolute' => TRUE,
        ])->toString(),
      ],
    ];
    $form['#attached']['drupalSettings'] = $js_settings;

    $form['#prefix'] = '<div id="commerce-pos-report-eod-form-container">';
    $form['#suffix'] = '</div>';

    if (empty($form_state->getValue('results_container_id'))) {
      $form_state->setValue('results_container_id', 'commerce-pos-report-results-container');
    }

    $form_ajax = [
      'callback' => '::formAjaxRefresh',
      'wrapper' => 'commerce-pos-report-eod-form-container',
    ];
    $results_ajax = [
      'callback' => '::previousReportsAjaxRefresh',
      'wrapper' => $form_state->getValue('results_container_id'),
      'effect' => 'fade',
    ];

    // Get all the registers.
    $registers = \Drupal::service('commerce_pos.registers')->getRegisters();
    if (empty($registers)) {
      // Return no registers error, link to setup registers.
      drupal_set_message($this->t('POS Orders can\'t be created until a register has been created. <a href=":url">Add a new register.</a>', [
        ':url' => URL::fromRoute('entity.commerce_pos_register.add_form')
          ->toString(),
      ]), 'error');

      return $form;
    }
    $register_options = ['' => '-'];
    foreach ($registers as $id => $register) {
      $register_options[$id] = $register->getName();
    }

    // Our filters.
    $form['filters'] = [
      '#type' => 'container',
      '#attributes' => ['class' => ['clearfix']],
    ];

    // Date filters.
    $date_filter = !empty($form_state->getValue('date')) ? $form_state->getValue('date') : date('Y-m-d', time());
    $today_filter = $form_state->getValue('date') == date('Y-m-d', time());

    $form['filters']['date'] = [
      '#type' => 'date',
      '#title' => $this->t('Transaction Date'),
      '#description' => $this->t('The day you wish to view or close a report from.'),
      '#default_value' => $date_filter,
    // '#ajax' => $results_ajax,.
      '#ajax' => $form_ajax,
    ];

    // Register ID filter.
    /** @var \Drupal\commerce_pos\Entity\Register $current_register */
    $current_register = \Drupal::service('commerce_pos.current_register')->get();
    if ($form_state->hasValue('register_id')) {
      $register_id = $form_state->getValue('register_id');
    }
    elseif ($current_register) {
      $register_id = $current_register->id();
    }

    $form['filters']['register_id'] = [
      '#type' => 'select',
      '#title' => $this->t('Register'),
      '#description' => $this->t('The register you wish to view or close a report on. Defaults to your current register if available.'),
      '#options' => $register_options,
      '#default_value' => $current_register ? $current_register->id() : NULL,
      '#ajax' => $results_ajax,
    ];

    /** @var \Drupal\commerce_pos_reports\ReportGenerator $report_generator */
    $report_generator = \Drupal::service('commerce_pos_reports.report_generator');
    $report_versions = $report_generator->getReportVersions($date_filter, $register_id);
    if (!empty($report_versions)) {
      $form['filters']['version_timestamp'] = [
        '#type' => 'select',
        '#title' => $this->t('Versions'),
        '#description' => $this->t('The version of the report you wish to view/edit.'),
        '#options' => $report_versions,
        '#ajax' => $results_ajax,
      ];
      $form['results'] = [
        '#type' => 'container',
        '#id' => $form_state->getValue('results_container_id'),
      ];

      if (!empty($register_id)) {
        $register = Register::load($register_id);
        $can_save = $register->isOpen();

        // Get saved data for requested date.
        $headers = [
          $this->t('Payment Type'),
          $this->t('Declared Amount'),
          $this->t('POS expected Amount'),
          $this->t('Over/Short'),
          $this->t('Cash Deposit'),
        ];

        $form['results']['actions'] = [
          '#type' => 'actions',
        ];

        // Get the totals summary for the selected date and register.
        if (!empty($form_state->getUserInput()) && $form_state->getUserInput()['version_timestamp'] != NULL) {
          $version_timestamp_value = $form_state->getUserInput()['version_timestamp'];
        }
        else {
          $version_timestamp_value = reset(array_keys($report_versions));
        }
        $report_history = $report_generator->getReportsByVersionTimestamp($version_timestamp_value, $register_id);
//        $totals_array = [
//          $register->getStore()->getDefaultCurrency()->getCurrencyCode() => [
//            'pos_cash' => $report_history['data']['pos_cash']['declared'],
//            'pos_credit' => $report_history['data']['pos_credit']['declared'],
//            'pos_debit' => $report_history['data']['pos_debit']['declared'],
//            'pos_gift_card' => $report_history['data']['pos_gift_card']['declared'],
//          ],
//        ];
        list($totals, $transaction_counts) = commerce_pos_reports_get_totals($date_filter, $register_id);

        $payment_gateway_options = commerce_pos_reports_get_payment_gateway_options();
        $number_formatter_factory = \Drupal::service('commerce_price.number_formatter_factory');
        $number_formatter = $number_formatter_factory->createInstance();

        // Display a textfield to enter the amounts for each currency type and
        // payment method.
        foreach ($totals_array as $currency_code => $currency_totals) {
          $form['results'][$currency_code] = [
            '#theme' => 'commerce_pos_reports_end_of_day_result_table',
            '#header' => $headers,
            'rows' => [
              '#tree' => TRUE,
            ],
            '#tree' => TRUE,
          ];

          foreach ($currency_totals as $payment_method_id => $amounts) {
            // Determine if this is a cash payment method.
            $is_cash = $payment_method_id == 'pos_cash';

            /** @var \Drupal\commerce_price\Entity\Currency $currency */
            $currency = Currency::load($currency_code);
            $row = [];

            $expected_amount = $amounts;
            $input_prefix = $currency->getSymbol();
            $input_suffix = '';

            if ($is_cash) {
              $register = Register::load($register_id);
              $expected_amount += $register->getOpeningFloat()->getNumber();
            }

            // Count group.
            $row['title'] = [
              '#markup' => $payment_gateway_options[$payment_method_id],
            ];

            // Declared amount.
            $declared = [
              '#type' => 'textfield',
              '#size' => 10,
              '#maxlength' => 10,
              '#attributes' => [
                'class' => ['commerce-pos-report-declared-input'],
                'data-currency-code' => $currency_code,
                'data-amount' => 0,
                'data-payment-method-id' => $payment_method_id,
                'data-expected-amount' => $expected_amount,
              ],
              '#element_validate' => ['::validateAmount'],
              '#required' => TRUE,
              '#disabled' => !$can_save && !$can_update,
              '#field_prefix' => $input_prefix,
              '#field_suffix' => $input_suffix,
            ];

            if ($is_cash) {
              $declared['#commerce_pos_keypad'] = [
                'type' => 'cash input',
                'currency_code' => $currency_code,
              ];

              $declared['#attributes']['data-default-float'] = $register->getDefaultFloat()->getNumber();
            }

            // Adding this element with the register_id and date as the keys
            // because this is a known issue w/ Drupal where default values
            // don't get changed during ajax callbacks. Adding a unique key to
            // the form element fixes the issue.
            $row['declared'][$register_id][$date_filter] = $declared;

            if (isset($report_history['data'][$payment_method_id]['declared'])) {
              $row['declared'][$register_id][$date_filter]['#default_value'] = $report_history['data'][$payment_method_id]['declared'];
              $row['declared'][$register_id][$date_filter]['#value'] = $report_history['data'][$payment_method_id]['declared'];
            }

            // Expected amount.
            $row[] = [
              '#markup' => '<div class="commerce-pos-report-expected-amount" data-payment-method-id="' . $payment_method_id . '">'
                . $number_formatter->formatCurrency($expected_amount, $currency)
                . '</div>',
            ];

            // Over/short.
            $over_short_amount = $report_history['data'][$payment_method_id]['declared'] - $expected_amount;
            $row[] = [
              '#markup' => '<div class="commerce-pos-report-balance" data-payment-method-id="' . $payment_method_id . '">'
                . ($over_short_amount > -1 ? $number_formatter->formatCurrency($over_short_amount, $currency) : '<span class="commerce-pos-report-balance commerce-pos-report-negative">(' . $number_formatter->formatCurrency(abs($over_short_amount), $currency) . ')</span>')
                . '</div>',
            ];

            // Cash Deposit.
            // Adding this element with the register_id and date as the keys
            // because this is a known issue w/ Drupal where default values
            // don't get changed during ajax callbacks. Adding a unique key to
            // the form element fixes the issue.
            if ($is_cash) {
              $row['cash_deposit'][$register_id][$date_filter] = [
                '#type' => 'textfield',
                '#size' => 10,
                '#maxlength' => 10,
                '#attributes' => [
                  'class' => ['commerce-pos-report-deposit'],
                ],
                '#title' => $this->t('Cash Deposit'),
                '#title_display' => 'invisible',
                '#field_prefix' => $input_prefix,
                '#field_suffix' => $input_suffix,
                '#disabled' => !$can_save && !$can_update,
              ];

              if (isset($report_history['data'][$payment_method_id]['cash_deposit'])) {
                $row['cash_deposit'][$register_id][$date_filter]['#default_value'] = $report_history['data'][$payment_method_id]['cash_deposit'];
              }
            }
            else {
              $row['cash_deposit'] = [
                '#markup' => '&nbsp;',
              ];
            }

            $form['results'][$currency_code]['rows'][$payment_method_id] = $row;
          }
        }

        if (!empty($totals_array)) {
          $js_settings['commercePosReportCurrencies'] = commerce_pos_reports_currency_js(array_keys($totals_array));
          $form['results']['#attached']['drupalSettings'] = $js_settings;
        }

        $form['results']['actions'] = [
          '#type' => 'actions',
        ];

        // Add this for user's who can't update if
        // they are still on the same date.
        if ($can_update || $today_filter) {
          $form['results']['actions']['update'] = [
            '#type' => 'submit',
            '#value' => $this->t('Update Report'),
            '#validate' => ['::updateValidate'],
            '#submit' => ['::updateSubmit'],
          ];
        }

        // The save and print buttons.
        if (!empty($totals_array)) {
          $form['results']['actions']['print'] = [
            '#type' => 'submit',
            '#value' => $this->t('Print'),
            '#ajax' => [
              'callback' => '::endOfDayPrintJs',
              'wrapper' => 'commerce-pos-report-eod-form-container',
            ],
          ];
        }
      }
    }
    else {
      $form['results']['error'] = [
        '#markup' => $this->t('There are no reports for this day.'),
      ];
    }

    return $form;
  }

  /**
   * Form submission handler.
   *
   * @param array $form
   *   An associative array containing the structure of the form.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The current state of the form.
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    // TODO: Implement submitForm() method.
  }

  /**
   * AJAX callback for the report "print" button.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return \Drupal\Core\Ajax\AjaxResponse
   *   Returns the ajax print response.
   */
  public function endOfDayPrintJs(array &$form, FormStateInterface $form_state) {
    $response = new AjaxResponse();

    // Output any status messages first.
    $status_messages = ['#type' => 'status_messages'];
    $output = \Drupal::service('renderer')->renderRoot(($status_messages));
    if (!empty($output)) {
      $response->addCommand(new PrependCommand('.page-content', $output));
    }

    // Now, if we have no errors, let's print the receipt.
    if (!$form_state->getErrors()) {
      $date = $form_state->getValue('date');
      $register_id = $form_state->getValue('register_id');

      $response->addCommand(new PrintEodReport($date, $register_id));
    }

    return $response;
  }

  /**
   * AJAX callback for the report filter elements.
   *
   * @param array $form
   *   The form array.
   * @param \Drupal\Core\Form\FormStateInterface $form_state
   *   The form state object.
   *
   * @return mixed
   *   Return the form with updated results.
   */
  public function previousReportsAjaxRefresh(array &$form, FormStateInterface $form_state) {
    return $form['results'];
  }

  /**
   *
   */
  public function formAjaxRefresh(array &$form, FormStateInterface $form_state) {
    return $form;
  }

}
