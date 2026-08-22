<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\NeoInlineCssGenerator;
use Drupal\neo_build\Preparer;
use Drupal\neo_build\Scope;
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
   * The persisted tag carries the module's own name.
   *
   * The only literal assertion in this file, and the one the rename makes
   * possible. Every other tag assertion reads the constant, so the value can
   * move without touching them; this one is here to catch it moving back.
   * It names the new tag and not the old one on purpose — the predecessor
   * suite's name is supposed to appear nowhere in the module, tests included.
   */
  public function testPersistsTheRenamedTagLiteral(): void {
    $this->generator()->generate();

    $this->assertContains('neo_build:build', $this->monitoredTags());
    $this->assertSame('neo_build:build', Preparer::BUILD_CACHE_TAG);
    $this->assertSame('neo_build:build:dev', Preparer::DEV_BUILD_CACHE_TAG);
  }

  /**
   * Invalidating the build cache tag regenerates the files on terminate.
   */
  public function testRegeneratesWhenTheBuildCacheTagIsInvalidated(): void {
    $this->generator()->generate();
    foreach (Scope::cases() as $scope) {
      $this->container->get('file_system')->delete($this->cssPath($scope->value));
      $this->assertFileDoesNotExist($this->cssPath($scope->value));
    }

    $this->invalidate([Preparer::BUILD_CACHE_TAG]);
    $this->terminate();

    foreach (Scope::cases() as $scope) {
      $this->assertFileExists($this->cssPath($scope->value));
    }
  }

  /**
   * An unrelated tag leaves the files alone.
   */
  public function testDoesNotRegenerateForAnUnrelatedTag(): void {
    $this->generator()->generate();
    foreach (Scope::cases() as $scope) {
      $this->container->get('file_system')->delete($this->cssPath($scope->value));
    }

    $this->invalidate(['neo_build_test:not_monitored']);
    $this->terminate();

    foreach (Scope::cases() as $scope) {
      $this->assertFileDoesNotExist($this->cssPath($scope->value));
    }
  }

  /**
   * Exactly one stylesheet is written, per scope, and nothing else.
   *
   * The weaker assertion this replaces looked up two files by name and would
   * have stayed green while a third scope produced nothing at all. Counting
   * the directory against the case list is what makes a silent scope fail.
   */
  public function testWritesExactlyOneStylesheetPerScope(): void {
    $this->generator()->generate();

    $expected = [];
    foreach (Scope::cases() as $scope) {
      $expected[] = $scope->value . '.css';
      $this->assertNotSame('', (string) file_get_contents($this->cssPath($scope->value)));
    }
    $written = array_values(array_filter(
      (array) scandir('public://neo-build'),
      static fn (string $file): bool => str_ends_with($file, '.css'),
    ));

    $this->assertEqualsCanonicalizing($expected, $written);
    $this->assertCount(count(Scope::cases()), $written);
  }

  /**
   * The existence check covers every scope, not two files by name.
   */
  public function testEnsureGeneratedRestoresEveryScopesStylesheet(): void {
    $this->generator()->generate();
    foreach (Scope::cases() as $scope) {
      $this->container->get('file_system')->delete($this->cssPath($scope->value));
    }

    // A fresh service: `ensureGenerated()` only looks once per request, and
    // the container's instance has already looked during the generate above.
    $generator = new NeoInlineCssGenerator(
      $this->container->get('event_dispatcher'),
      $this->container->get('file_system'),
      $this->container->get('state'),
      $this->container->get('neo_build'),
    );
    $generator->ensureGenerated();

    foreach (Scope::cases() as $scope) {
      $this->assertFileExists($this->cssPath($scope->value));
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

    foreach (Scope::cases() as $scope) {
      $css = (string) file_get_contents($this->cssPath($scope->value));
      $this->assertStringContainsString(NeoBuildTestInlineSubscriber::CSS_PROPERTY . ': ' . $scope->value . ';', $css);
    }
  }

}
