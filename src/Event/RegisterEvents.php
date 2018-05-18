<?php

namespace Drupal\commerce_pos\Event;

/**
 * The register events class.
 */
final class RegisterEvents {

  /**
   * The name of the event fired when a register gets opened.
   *
   * @Event
   *
   * @see \Drupal\commerce_pos\Event\RegisterOpenEvent
   */
  const REGISTER_OPEN = 'commerce_pos.register_open';

}
