<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\NeoInlineCssGenerator;
use Drupal\neo_build\Preparer;
use Drupal\neo_build_test\EventSubscriber\NeoBuildTestInlineSubscriber;
use PHPUnit\Framework\Attributes\Group;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Event\TerminateEvent;
use Symfony\Component\HttpKernel\HttpKernelInterface;
use Symfony\Component\HttpKernel\KernelEvents;

/**
 * Tests the prepare → inline-CSS regeneration link.
 *
 * The generator dispatches a per-scope inline event, writes each scope's CSS to
 * public://neo-build/{scope}.css, and persists the union of the event's cache
 * tags to state. A later invalidation of any tag in that set marks it for
 * regeneration, and the terminate subscriber regenerates once the response is
 * sent. Every sibling package's inline-event subscriber depends on that chain
 * and, until this test, nothing asserted a step of it.
 *
 * The tag assertions read Preparer::BUILD_CACHE_TAG rather than its value, so
 * renaming the tag moves it underneath them without touching a line here.
 */
#[Group('neo_build')]
class NeoInlineCssGeneratorTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
    'neo_build_test',
    'neo_build_test_back',
  ];

  /**
   * The real generator service, container registrations and all.
   */
  protected function generator(): NeoInlineCssGenerator {
    return $this->container->get('neo_build.inline_css_generator');
  }

  /**
   * The tags the last generate persisted to state.
   */
  protected function monitoredTags(): array {
    return $this->container->get('state')->get('neo_build.inline_tags', []);
  }

  /**
   * A scope's generated CSS file.
   */
  protected function cssPath(string $scope): string {
    return 'public://neo-build/' . $scope . '.css';
  }

  /**
   * Invalidates through the real invalidator, covering the service tag.
   */
  protected function invalidate(array $tags): void {
    $this->container->get('cache_tags.invalidator')->invalidateTags($tags);
  }

  /**
   * Dispatches a real terminate, covering the event_subscriber registration.
   */
  protected function terminate(): void {
    $kernel = $this->createMock(HttpKernelInterface::class);
    $event = new TerminateEvent($kernel, Request::create('/'), new Response());
    $this->container->get('event_dispatcher')->dispatch($event, KernelEvents::TERMINATE);
  }

  /**
   * A generate persists the build cache tag into state.
   */
  public function testPersistsTheBuildCacheTagIntoState(): void {
    $this->generator()->generate();

    $this->assertContains(Preparer::BUILD_CACHE_TAG, $this->monitoredTags());
  }

  /**
   * Invalidating the build cache tag regenerates the files on terminate.
   */
  public function testRegeneratesWhenTheBuildCacheTagIsInvalidated(): void {
    $this->generator()->generate();
    foreach (['front', 'back'] as $scope) {
      $this->container->get('file_system')->delete($this->cssPath($scope));
      $this->assertFileDoesNotExist($this->cssPath($scope));
    }

    $this->invalidate([Preparer::BUILD_CACHE_TAG]);
    $this->terminate();

    foreach (['front', 'back'] as $scope) {
      $this->assertFileExists($this->cssPath($scope));
    }
  }

  /**
   * An unrelated tag leaves the files alone.
   */
  public function testDoesNotRegenerateForAnUnrelatedTag(): void {
    $this->generator()->generate();
    foreach (['front', 'back'] as $scope) {
      $this->container->get('file_system')->delete($this->cssPath($scope));
    }

    $this->invalidate(['neo_build_test:not_monitored']);
    $this->terminate();

    foreach (['front', 'back'] as $scope) {
      $this->assertFileDoesNotExist($this->cssPath($scope));
    }
  }

  /**
   * A subscriber's cache tag reaches the persisted monitored set.
   */
  public function testCarriesSubscriberCacheTagIntoTheMonitoredTags(): void {
    $this->generator()->generate();

    $this->assertContains(NeoBuildTestInlineSubscriber::CACHE_TAG, $this->monitoredTags());
  }

  /**
   * A subscriber's CSS value reaches that scope's file.
   */
  public function testWritesSubscriberCssValueIntoTheScopesFile(): void {
    $this->generator()->generate();

    foreach (['front', 'back'] as $scope) {
      $css = (string) file_get_contents($this->cssPath($scope));
      $this->assertStringContainsString(NeoBuildTestInlineSubscriber::CSS_PROPERTY . ': ' . $scope . ';', $css);
    }
  }

}
