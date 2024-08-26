<?php

declare(strict_types = 1);

namespace Drupal\neo_build\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event that is fired when a user logs in.
 */
class NeoBuildEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'neo_build';

  /**
   * The Neo build config.
   *
   * @var array
   */
  public $config;

  /**
   * The document root.
   *
   * @var string
   */
  public $docRoot;

  /**
   * Constructs the object.
   *
   * @param array $config
   *   The Neo build config.
   * @param string $docRoot
   *   The document root.
   */
  public function __construct(array $config, string $docRoot) {
    $this->setConfig($config);
    $this->docRoot = $docRoot;
  }

  /**
   * Gets the Neo build config.
   *
   * @return array
   *   The configuration.
   */
  public function getConfig() {
    return $this->config;
  }

  /**
   * Sets the Neo build config.
   *
   * @param array $config
   *   The configuration to set.
   */
  public function setConfig(array $config) {
    $this->config = $config;
  }

  /**
   * Gets the document root.
   *
   * @return string
   *   The document root.
   */
  public function getDocRoot() {
    return $this->docRoot;
  }

}
