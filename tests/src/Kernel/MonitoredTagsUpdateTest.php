<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\Preparer;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the update that re-persists the monitored tags under the new name.
 *
 * The tags the inline CSS generator watches live in state, so a site deployed
 * with the rename keeps watching the predecessor suite's name until something
 * regenerates. In that window a prepare invalidates neo_build:build, the
 * generator is still watching the name it was deployed with, and the inline CSS
 * silently does not regenerate. The window closes on the next cache rebuild —
 * but "whatever clears caches next" is not a deployment step, so an update hook
 * closes it at drush updatedb instead.
 *
 * Verified as a migration rather than as a generator call: state is seeded with
 * what a deployed site actually carries, and the assertions are about what
 * replaced it. The generator itself is covered by NeoInlineCssGeneratorTest.
 */
#[Group('neo_build')]
class MonitoredTagsUpdateTest extends KernelTestBase {

  /**
   * The tag names a site deployed before the rename still has in state.
   *
   * The one place the predecessor suite's name still appears in this module,
   * and it appears as data being migrated away from rather than as anything
   * the module calls itself.
   */
  private const STALE_TAGS = ['exo_build:build', 'exo_build:build:dev'];

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
  ];

  /**
   * Runs the update hook the way update.php would.
   */
  private function runUpdate(): ?string {
    $this->container->get('module_handler')->loadInclude('neo_build', 'install');
    return neo_build_update_11002();
  }

  /**
   * The stale tag names are replaced by the renamed ones.
   */
  public function testReplacesTheStaleMonitoredTags(): void {
    $state = $this->container->get('state');
    $state->set('neo_build.inline_tags', self::STALE_TAGS);

    $this->runUpdate();

    $tags = $state->get('neo_build.inline_tags');
    $this->assertContains(Preparer::BUILD_CACHE_TAG, $tags);
    foreach (self::STALE_TAGS as $stale) {
      $this->assertNotContains($stale, $tags);
    }
  }

  /**
   * The update rewrites the inline CSS files.
   */
  public function testRewritesTheInlineCssFiles(): void {
    $this->container->get('state')->set('neo_build.inline_tags', self::STALE_TAGS);
    foreach (['front', 'back'] as $scope) {
      $this->assertFileDoesNotExist('public://neo-build/' . $scope . '.css');
    }

    $this->runUpdate();

    foreach (['front', 'back'] as $scope) {
      $this->assertFileExists('public://neo-build/' . $scope . '.css');
    }
  }

  /**
   * The update returns a sentence describing what it did.
   */
  public function testReturnsSentenceDescribingWhatItDid(): void {
    $this->container->get('state')->set('neo_build.inline_tags', self::STALE_TAGS);

    $message = $this->runUpdate();

    $this->assertIsString($message);
    $this->assertNotSame('', $message);
    $this->assertStringEndsWith('.', $message);
  }

}
