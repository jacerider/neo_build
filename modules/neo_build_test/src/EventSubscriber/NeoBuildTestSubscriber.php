<?php

declare(strict_types=1);

namespace Drupal\neo_build_test\EventSubscriber;

use Drupal\Core\State\StateInterface;
use Drupal\neo_build\Event\NeoBuildEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;

/**
 * Leaves fingerprints a kernel test can find after a prepare.
 *
 * Adds a theme item the neo.json generator will carry, and records which
 * scoped extensions the event was dispatched with.
 */
final class NeoBuildTestSubscriber implements EventSubscriberInterface {

  /**
   * The state key under which the event's extension names are recorded.
   */
  public const STATE_KEY = 'neo_build_test.build_event_extensions';

  /**
   * The theme key the subscriber adds.
   */
  public const THEME_KEY = 'neoBuildTest';

  /**
   * Constructs a NeoBuildTestSubscriber.
   *
   * @param \Drupal\Core\State\StateInterface $state
   *   The state.
   */
  public function __construct(
    private readonly StateInterface $state,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [NeoBuildEvent::EVENT_NAME => 'onBuild'];
  }

  /**
   * Adds a theme item and records the scoped extensions.
   *
   * @param \Drupal\neo_build\Event\NeoBuildEvent $event
   *   The build event.
   */
  public function onBuild(NeoBuildEvent $event): void {
    $event->getCollection()->addTailwindThemeItem(self::THEME_KEY, 'subscriber');
    $this->state->set(self::STATE_KEY, array_keys($event->getExtensions()));
  }

}
