<?php

namespace Drupal\commerce_pos\Entity;

use Drupal\Core\Entity\ContentEntityInterface;

/**
 * Provides an interface for defining Register entities.
 */
interface RegisterInterface extends ContentEntityInterface {

  /**
   * Gets the store.
   *
   * @return \Drupal\commerce_store\Entity\StoreInterface|null
   *   The store entity, or null.
   */
  public function getStore();

  /**
   * Sets the store.
   *
   * @param \Drupal\commerce_store\Entity\StoreInterface $store
   *   The store entity.
   *
   * @return $this
   */
  public function setStore(StoreInterface $store);

  /**
   * Gets the store ID.
   *
   * @return int
   *   The store ID.
   */
  public function getStoreId();

  /**
   * Sets the store ID.
   *
   * @param int $store_id
   *   The store ID.
   *
   * @return $this
   */
  public function setStoreId($store_id);
}
