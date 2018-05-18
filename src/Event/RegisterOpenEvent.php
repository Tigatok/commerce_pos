<?php

namespace Drupal\commerce_pos\Event;

use Drupal\commerce_pos\Entity\RegisterInterface;
use Symfony\Component\EventDispatcher\Event;

/**
 *
 */
class RegisterOpenEvent extends Event {

  protected $register;

  /**
   *
   */
  public function __construct(RegisterInterface $register) {
    $this->register = $register;
  }

  /**
   *
   */
  public function getRegister() {
    return $this->register;
  }

}
