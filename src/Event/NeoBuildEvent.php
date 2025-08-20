<?php

declare(strict_types=1);

namespace Drupal\neo_build\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\neo_build\NeoBuildCollection;

/**
 * Event that is fired when a user logs in.
 */
class NeoBuildEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'new_neo_build';

  /**
   * The data collection.
   *
   * @var \Drupal\neo_build\NeoBuildCollection
   */
  private NeoBuildCollection $collection;

  /**
   * The scoped extensions.
   *
   * @var \Drupal\neo_build\NeoExtension[]
   */
  private array $extensions;

  /**
   * Constructs the object.
   */
  public function __construct(NeoBuildCollection $collection, array $extensions = []) {
    $this->collection = $collection;
    $this->extensions = $extensions;
  }

  /**
   * Gets the data collection.
   *
   * @return \Drupal\neo_build\NeoBuildCollection
   *   The data collection.
   */
  public function getCollection(): NeoBuildCollection {
    return $this->collection;
  }

  /**
   * Gets the scoped extensions.
   *
   * @return \Drupal\neo_build\NeoExtension[]
   *   The scoped extensions.
   */
  public function getExtensions(): array {
    return $this->extensions;
  }

}
