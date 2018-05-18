<?php

namespace Drupal\commerce_pos\Event;

use Drupal\commerce_pos\Entity\RegisterInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 * Event class for capturing register opens.
 */
class RegisterOpenEvent extends Event {

  protected $register;

  /**
   * {@inheritdoc}
   */
  public function __construct(RegisterInterface $register) {
    $this->register = $register;
  }

  /**
   * Returns the register.
   */
  public function getRegister() {
    return $this->register;
  }

}
