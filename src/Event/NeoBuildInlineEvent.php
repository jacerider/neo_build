<?php

declare(strict_types = 1);

namespace Drupal\neo_build\Event;

use Drupal\Component\EventDispatcher\Event;

/**
 * Event that is fired to allow for custom CSS to be added.
 */
class NeoBuildInlineEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'neo_build_inline';

  /**
   * Whether or not the site is in development mode.
   *
   * @var bool
   */
  public $devMode;

  /**
   * The Neo build data.
   *
   * @var array
   */
  public $data;

  /**
   * The cache tags.
   *
   * @var array
   */
  public $cacheTags;

  /**
   * Constructs the object.
   *
   * @param bool $devMode
   *   Whether or not the site is in development mode.
   */
  public function __construct(bool $devMode = FALSE) {
    $this->devMode = $devMode;
    $this->data = [];
    $this->cacheTags = [
      'exo_build:build',
    ];
    if ($devMode) {
      $this->cacheTags[] = 'exo_build:build:dev';
    }
  }

  /**
   * Determines if the site is in development mode.
   *
   * @return bool
   *   TRUE if the site is in development mode, FALSE otherwise.
   */
  public function isDevMode() {
    return $this->devMode === TRUE;
  }

  /**
   * Adds a CSS value to the specified attribute and group.
   *
   * @param string $attribute
   *   The CSS attribute.
   * @param string $value
   *   The CSS value.
   * @param string|null $group
   *   (optional) The CSS group. Defaults to ':root' if not provided.
   *
   * @return $this
   */
  public function addCssValue(string $attribute, string $value, string $group = NULL): self {
    $group = $group ?? ':root';
    $this->data[$group][$attribute] = $value;
    return $this;
  }

  /**
   * Gets the Neo build data.
   *
   * @return array
   *   The datauration.
   */
  public function getData() {
    return $this->data;
  }

  /**
   * Retrieves the CSS styles based on the data stored in the object.
   *
   * @return string
   *   The CSS styles as a string.
   */
  public function getCss() {
    $css = '';
    foreach ($this->data as $groupId => $group) {
      if (!empty($group)) {
        $css .= "$groupId{";
        foreach ($group as $attribute => $value) {
          $css .= "$attribute: $value;";
        }
        $css .= '}';
      }
    }
    return $css;
  }

  /**
   * Adds cache tags to the existing cache tags array.
   *
   * @param array $tags
   *   An array of cache tags to be added.
   */
  public function addCacheTags(array $tags) {
    $this->cacheTags = array_merge($this->cacheTags, $tags);
  }

  /**
   * Returns the cache tags associated with the NeoBuildDevEvent.
   *
   * @return array
   *   An array of cache tags.
   */
  public function getCacheTags() {
    return array_unique($this->cacheTags);
  }

}
