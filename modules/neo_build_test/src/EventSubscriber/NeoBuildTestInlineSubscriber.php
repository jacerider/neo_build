<?php

declare(strict_types=1);

namespace Drupal\neo_build_test\EventSubscriber;

use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Stands in for a sibling package's inline-event subscriber.
 *
 * Six packages add inline CSS and a cache tag of their own this way, and rely
 * on the generator carrying both through: the tag into the persisted monitored
 * set, the value into that scope's file. This fixture holds up the same half of
 * the contract so a kernel test can pin it.
 */
final class NeoBuildTestInlineSubscriber implements EventSubscriberInterface {

  /**
   * The cache tag the subscriber adds to the event.
   */
  public const CACHE_TAG = 'neo_build_test:inline';

  /**
   * The custom property the subscriber writes into each scope's CSS.
   */
  public const CSS_PROPERTY = '--neo-build-test-scope';

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [NeoBuildInlineEvent::EVENT_NAME => 'onInline'];
  }

  /**
   * Adds a scope-stamped CSS value and a cache tag of the subscriber's own.
   *
   * The value carries the scope so the test can tell the two dispatches apart.
   *
   * @param \Drupal\neo_build\Event\NeoBuildInlineEvent $event
   *   The inline event.
   */
  public function onInline(NeoBuildInlineEvent $event): void {
    $event->addCssValue(self::CSS_PROPERTY, $event->getThemeName());
    $event->addCacheTags([self::CACHE_TAG]);
  }

}
