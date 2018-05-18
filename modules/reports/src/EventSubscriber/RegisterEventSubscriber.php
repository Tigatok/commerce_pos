<?php

namespace Drupal\commerce_pos_reports\EventSubscriber;

use Drupal\commerce_pos\Event\RegisterOpenEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 *
 */
class RegisterEventSubscriber implements EventSubscriberInterface {

  /**
   * Returns an array of event names this subscriber wants to listen to.
   *
   * The array keys are event names and the value can be:
   *
   *  * The method name to call (priority defaults to 0)
   *  * An array composed of the method name to call and the priority
   *  * An array of arrays composed of the method names to call and respective
   *    priorities, or 0 if unset
   *
   * For instance:
   *
   *  * array('eventName' => 'methodName')
   *  * array('eventName' => array('methodName', $priority))
   *  * array('eventName' => array(array('methodName1', $priority), array('methodName2')))
   *
   * @return array The event names to listen to
   */
  public static function getSubscribedEvents() {
    return [
      'commerce_pos.register_open' => ['onRegisterOpen'],
    ];
  }

  /**
   * The event that fires on Register Open.
   */
  public function onRegisterOpen(RegisterOpenEvent $registerOpenEvent) {
    /** @var \Drupal\commerce_pos_reports\ReportGenerator $reportGenerator */
    $reportGenerator = \Drupal::service('commerce_pos_reports.report_generator');
    $date = date('y-m-d', time());
    $reportGenerator->createReport($date, $registerOpenEvent->getRegister()->id(), NULL, FALSE);
  }

}
