<?php

declare(strict_types=1);

namespace Drupal\neo_build\Event;

use Drupal\Component\EventDispatcher\Event;
use Drupal\neo_build\Preparer;
use Drupal\neo_build\Scope;

/**
 * Event that is fired to allow for custom CSS to be added.
 */
class NeoBuildInlineEvent extends Event {

  // This makes it easier for subscribers to reliably use our event name.
  const EVENT_NAME = 'neo_build_inline';

  /**
   * The scope this event was dispatched for.
   *
   * @var \Drupal\neo_build\Scope
   */
  public $scope;

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
   * @param \Drupal\neo_build\Scope $scope
   *   The scope this event is dispatched for.
   * @param bool $devMode
   *   Whether or not the site is in development mode.
   */
  public function __construct(Scope $scope, bool $devMode = FALSE) {
    $this->scope = $scope;
    $this->devMode = $devMode;
    $this->data = [];
    $this->cacheTags = [
      Preparer::BUILD_CACHE_TAG,
    ];
    if ($devMode) {
      $this->cacheTags[] = Preparer::DEV_BUILD_CACHE_TAG;
    }
  }

  /**
   * Gets the scope this event was dispatched for.
   *
   * Renamed from `getThemeName()`, which was only ever right by accident: a
   * scope's id and its theme's machine name are the same string. Nothing
   * wraps the old name — a subscriber outside this repository that read it
   * has to move, and is meant to find out at once rather than later.
   *
   * @return \Drupal\neo_build\Scope
   *   The scope.
   */
  public function getScope(): Scope {
    return $this->scope;
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
   * @param string|array $value
   *   The CSS value.
   * @param string $group
   *   The CSS group. Defaults to ':root' if not provided.
   *
   * @return $this
   */
  public function addCssValue(string $attribute, string|array $value, string $group = ':root'): self {
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
    foreach ($this->getData() as $groupId => $group) {
      if (!empty($group)) {
        $strings = array_filter($group, 'is_string');
        $arrays = array_filter($group, 'is_array');
        if ($strings) {
          $css .= "$groupId{";
          foreach ($strings as $attribute => $value) {
            $css .= "$attribute: $value;";
          }
          $css .= '}';
        }
        if ($arrays) {
          foreach ($arrays as $values) {
            $strings = array_filter($values, 'is_string');
            if ($strings) {
              $css .= "$groupId{";
              foreach ($strings as $attribute => $value) {
                $css .= "$attribute: $value;";
              }
              $css .= '}';
            }
          }
        }
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
