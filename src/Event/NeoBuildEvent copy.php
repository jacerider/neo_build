<?php

declare(strict_types=1);

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
   * The scoped modules/themes.
   *
   * @var array
   */
  public $scopedExtensions;

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
   * @param array $scopedExtensions
   *   The scoped modules/themes.
   * @param string $docRoot
   *   The document root.
   */
  public function __construct(array $config, array $scopedExtensions, string $docRoot) {
    $this->setConfig($config);
    $this->scopedExtensions = $scopedExtensions;
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
   * Gets the Neo-enabled modules/themes by scope.
   *
   * @return array
   *   The scoped modules/themes.
   */
  public function getScopedExtensions() {
    return $this->scopedExtensions;
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
