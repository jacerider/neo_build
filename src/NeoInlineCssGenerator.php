<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\Core\File\FileExists;
use Drupal\Core\File\FileSystemInterface;
use Drupal\Core\State\StateInterface;
use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Symfony\Component\EventDispatcher\EventSubscriberInterface;
use Symfony\Component\HttpKernel\KernelEvents;
use Symfony\Contracts\EventDispatcher\EventDispatcherInterface;

/**
 * Generates inline CSS files for Neo Build.
 *
 * Instead of checking cache on every page load (hook_page_top), this service
 * generates CSS files proactively:
 * - On cache rebuild via hook_rebuild().
 * - After cache tag invalidation via KernelEvents::TERMINATE (post-response).
 */
class NeoInlineCssGenerator implements CacheTagsInvalidatorInterface, EventSubscriberInterface {

  /**
   * Whether CSS files need regeneration this request.
   */
  private bool $needsRegeneration = FALSE;

  /**
   * Cache tags loaded from state (loaded once per request).
   */
  private ?array $monitoredTags = NULL;

  /**
   * Whether file existence has been verified this request.
   */
  private bool $verified = FALSE;

  /**
   * Constructs a NeoInlineCssGenerator.
   */
  public function __construct(
    private readonly EventDispatcherInterface $eventDispatcher,
    private readonly FileSystemInterface $fileSystem,
    private readonly StateInterface $state,
    private readonly NeoBuild $neoBuild,
  ) {}

  /**
   * Generates inline CSS files for all scopes.
   */
  public function generate(): void {
    $isDevMode = $this->neoBuild->isDevMode();
    $directory = 'public://neo-build';
    if (!$this->fileSystem->prepareDirectory($directory, FileSystemInterface::CREATE_DIRECTORY | FileSystemInterface::MODIFY_PERMISSIONS)) {
      return;
    }

    $allTags = [];
    foreach (Scope::cases() as $scope) {
      $event = new NeoBuildInlineEvent($scope->value, $isDevMode);
      $this->eventDispatcher->dispatch($event, NeoBuildInlineEvent::EVENT_NAME);
      $allTags = array_merge($allTags, $event->getCacheTags());
      $this->fileSystem->saveData($event->getCss(), $directory . '/' . $scope->value . '.css', FileExists::Replace);
    }

    // Store monitored tags so invalidateTags() can detect future changes.
    $this->monitoredTags = array_unique($allTags);
    $this->state->set('neo_build.inline_tags', $this->monitoredTags);
    $this->needsRegeneration = FALSE;
    $this->verified = TRUE;
  }

  /**
   * Ensures CSS files exist (cold start / files directory cleared).
   *
   * This is a lightweight safety net — only checks file existence once per
   * request and only generates if files are missing.
   */
  public function ensureGenerated(): void {
    if (!$this->verified) {
      $this->verified = TRUE;
      foreach (Scope::cases() as $scope) {
        if (!file_exists('public://neo-build/' . $scope->value . '.css')) {
          $this->generate();
          return;
        }
      }
    }
  }

  /**
   * {@inheritdoc}
   */
  public function invalidateTags(array $tags): void {
    if ($this->monitoredTags === NULL) {
      $this->monitoredTags = $this->state->get('neo_build.inline_tags', []);
    }
    if (!empty(array_intersect($tags, $this->monitoredTags))) {
      $this->needsRegeneration = TRUE;
    }
  }

  /**
   * {@inheritdoc}
   */
  public static function getSubscribedEvents(): array {
    return [
      KernelEvents::TERMINATE => 'onTerminate',
    ];
  }

  /**
   * Regenerates CSS files after the response is sent.
   */
  public function onTerminate(): void {
    if ($this->needsRegeneration) {
      $this->generate();
    }
  }

}
