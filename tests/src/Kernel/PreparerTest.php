<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\Core\Cache\CacheTagsInvalidatorInterface;
use Drupal\KernelTests\KernelTestBase;
use Drupal\neo_build\Preparer;
use Drupal\neo_build\PrepareNotice;
use Drupal\neo_build\ProjectRootInterface;
use Drupal\neo_build\Scope;
use Drupal\neo_build_test\EventSubscriber\NeoBuildTestSubscriber;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the preparer against the neo_build_test fixture.
 *
 * Prepare turns the site's Neo extensions into the build's inputs. It used to
 * exist only as the body of the neo:build Drush command — uncallable from PHP,
 * untestable, and relied on by every sibling package's build-event subscriber
 * with nothing asserting a byte of what it wrote. These tests run the real
 * service against the fixture module and theme, into a temporary project root
 * the preparer has to create directories under, exactly as a fresh site would.
 */
#[Group('neo_build')]
class PreparerTest extends KernelTestBase {

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
   * The temporary project root prepare writes into.
   */
  protected string $root;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    // Not created on purpose: a temporary root has no directories yet, and
    // the preparer has to make the ones it writes into.
    $this->root = sys_get_temp_dir() . '/neo_build_preparer_' . $this->randomMachineName();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    if (is_dir($this->root)) {
      $this->container->get('file_system')->deleteRecursive($this->root);
    }
    parent::tearDown();
  }

  /**
   * Installs the fixture theme, whose CSS entrypoint is the primary file.
   */
  protected function installTheme(): void {
    $this->container->get('theme_installer')->install(['neo_build_test_theme']);
  }

  /**
   * Builds the real preparer service over a temporary project root.
   *
   * The project-root service is the one replaceable piece: everything else is
   * the container's own.
   */
  protected function preparer(): Preparer {
    $root = $this->root;
    $projectRoot = new class($root) implements ProjectRootInterface {

      /**
       * Constructs the stub.
       */
      public function __construct(private readonly string $root) {}

      /**
       * {@inheritdoc}
       */
      public function getRoot(): string {
        return $this->root;
      }

      /**
       * {@inheritdoc}
       */
      public function getDocRoot(): string {
        return 'web/';
      }

    };
    $this->container->set('neo_build.project_root', $projectRoot);
    return $this->container->get('neo_build.preparer');
  }

  /**
   * Swaps in a recording invalidator and returns it.
   *
   * Installed before the preparer is fetched, the same way the project root is.
   */
  protected function recordingInvalidator(): CacheTagsInvalidatorInterface {
    $spy = new class() implements CacheTagsInvalidatorInterface {

      /**
       * Every tag the preparer invalidated.
       *
       * @var string[]
       */
      public array $invalidated = [];

      /**
       * {@inheritdoc}
       */
      public function invalidateTags(array $tags): void {
        $this->invalidated = array_merge($this->invalidated, $tags);
      }

    };
    $this->container->set('cache_tags.invalidator', $spy);
    return $spy;
  }

  /**
   * Reads the written neo.json.
   */
  protected function neoJson(): array {
    return json_decode((string) file_get_contents($this->root . '/neo.json'), TRUE);
  }

  /**
   * Prepare writes the three root artifacts and the CSS beside the primary.
   */
  public function testPreparesTheScopeIntoTheTemporaryProjectRoot(): void {
    $this->installTheme();

    $result = $this->preparer()->prepare('front');

    $themePath = $this->container->get('extension.list.theme')->getPath('neo_build_test_theme');
    $css = $this->root . '/web/' . $themePath . '/src/css/tailwind.neo.css';
    $expected = [
      $this->root . '/neo.json',
      $this->root . '/neo.tsconfig.json',
      $this->root . '/phpstan.neon',
      $css,
    ];
    foreach ($expected as $file) {
      $this->assertFileExists($file);
    }
    $this->assertEqualsCanonicalizing($expected, $result->getArtifacts());
    $this->assertSame('front', $result->getScope());

    $neo = $this->neoJson();
    $this->assertSame($this->root . '/', $neo['root']);
    $this->assertSame('web/', $neo['docRoot']);
    $this->assertSame($themePath . '/src/css/neoBuildTestTheme.css', $neo['primaryFile']);
    $this->assertSame($themePath, $neo['primaryRoot']);
    $this->assertStringStartsWith("/*\n * NEO Tailwind CSS", (string) file_get_contents($css));
    // The front scope leaves the back-only extension's library out.
    $backOnly = $this->container->get('extension.list.module')->getPath('neo_build_test_back');
    $this->assertNotContains('./' . $backOnly . '/src/css/neoBuildTestBackOnly.css', $neo['vite']['lib']);
  }

  /**
   * The other scope's extensions are excluded and the scope lands in state.
   */
  public function testExcludesTheOtherScopesLibrariesAndRecordsTheScopeInState(): void {
    $this->installTheme();

    $this->preparer()->prepare('back');

    $neo = $this->neoJson();
    $modulePath = $this->container->get('extension.list.module')->getPath('neo_build_test');
    $backOnly = $this->container->get('extension.list.module')->getPath('neo_build_test_back');
    $this->assertContains('./' . $backOnly . '/src/css/neoBuildTestBackOnly.css', $neo['vite']['lib']);
    $this->assertContains('./' . $modulePath . '/src/css/neoBuildTestBack.css', $neo['vite']['lib']);
    $this->assertContains('./' . $modulePath . '/src/js/neoBuildTestBack.ts', $neo['vite']['lib']);
    $this->assertSame('back', $this->container->get('state')->get('neo.build.scope'));
  }

  /**
   * The build event is dispatched with the scoped extensions.
   *
   * The fixture subscriber adds a theme item, which has to reach neo.json,
   * and records the extension names the event carried.
   */
  public function testDispatchesTheBuildEventWithTheScopedExtensions(): void {
    $this->installTheme();

    $this->preparer()->prepare('front');

    $this->assertSame('subscriber', $this->neoJson()['tailwind']['theme'][NeoBuildTestSubscriber::THEME_KEY]);
    $seen = $this->container->get('state')->get(NeoBuildTestSubscriber::STATE_KEY);
    $this->assertContains('neo_build_test', $seen);
    $this->assertContains('neo_build_test_theme', $seen);
    $this->assertNotContains('neo_build_test_back', $seen);
  }

  /**
   * One notice per added extension and one per missing entrypoint.
   */
  public function testReturnsOneNoticePerAddedExtensionAndPerMissingEntrypoint(): void {
    $this->installTheme();

    $result = $this->preparer()->prepare('front');

    $added = array_map(fn (PrepareNotice $notice) => $notice->getMessage(), $result->getNotices(PrepareNotice::EXTENSION_ADDED));
    $this->assertContains('Extension added: Neo | Build | Test (neo_build_test)', $added);
    $this->assertContains('Extension added: Neo | Build | Test Theme (neo_build_test_theme)', $added);

    $missing = array_map(fn (PrepareNotice $notice) => $notice->getMessage(), $result->getNotices(PrepareNotice::MISSING_ENTRYPOINT));
    $modulePath = $this->container->get('extension.list.module')->getPath('neo_build_test');
    $this->assertSame(['Missing CSS file skipped: ' . $modulePath . '/src/css/neoBuildTestMissing.css (neo_build_test:missing)'], $missing);
    $this->assertSame([], $result->getNotices(PrepareNotice::MISSING_PRIMARY_FILE));
  }

  /**
   * A scope id string and its enum case prepare the same thing.
   *
   * Drush hands `neo:build <scope>` through as a string and that has to keep
   * working, so prepare normalises rather than choosing. The two calls are the
   * same operation described two ways, and nothing about the result may depend
   * on which way the caller said it.
   */
  public function testPreparesTheSameResultFromTheScopeIdAndFromTheEnumCase(): void {
    $this->installTheme();

    $fromString = $this->preparer()->prepare('front');
    $fromCase = $this->preparer()->prepare(Scope::Front);

    $this->assertSame($fromString->getScope(), $fromCase->getScope());
    $this->assertSame($fromString->getScopeLabel(), $fromCase->getScopeLabel());
    $this->assertEqualsCanonicalizing($fromString->getArtifacts(), $fromCase->getArtifacts());
  }

  /**
   * The prepared scope's label comes from the enum.
   */
  public function testTakesTheScopeLabelFromTheEnum(): void {
    $this->installTheme();

    foreach (Scope::cases() as $scope) {
      $result = $this->preparer()->prepare($scope);
      $this->assertSame($scope->value, $result->getScope());
      $this->assertSame($scope->label(), $result->getScopeLabel());
    }
  }

  /**
   * An unknown scope is rejected by the preparer.
   */
  public function testRejectsAnUnknownScope(): void {
    $this->expectException(\InvalidArgumentException::class);
    $this->expectExceptionMessage("Scope 'sideways' does not exist.");

    $this->preparer()->prepare('sideways');
  }

  /**
   * No primary file: a notice, and nothing written at the docroot root.
   */
  public function testReportsTheMissingPrimaryFileAndWritesNothingToTheDocrootRoot(): void {
    // No theme installed: the fixture module alone has no primary file.
    $result = $this->preparer()->prepare('front');

    $this->assertCount(1, $result->getNotices(PrepareNotice::MISSING_PRIMARY_FILE));
    $this->assertFileExists($this->root . '/neo.json');
    $this->assertFileDoesNotExist($this->root . '/web/tailwind.neo.css');
    $this->assertFileDoesNotExist($this->root . '/web/./tailwind.neo.css');
    $this->assertNull($this->neoJson()['primaryFile']);
    $written = array_filter($result->getArtifacts(), fn (string $path) => str_ends_with($path, 'tailwind.neo.css'));
    $this->assertSame([], $written);
  }

  /**
   * A prepare invalidates the build cache tag.
   *
   * The link the inline CSS regenerates on: prepare invalidates, the generator
   * is watching, the files are rewritten. Read through the constant, so a
   * rename of the tag moves this assertion with it.
   */
  public function testInvalidatesTheBuildCacheTagOnPrepare(): void {
    $this->installTheme();
    $invalidator = $this->recordingInvalidator();

    $this->preparer()->prepare('front');

    $this->assertContains(Preparer::BUILD_CACHE_TAG, $invalidator->invalidated);
  }

  /**
   * A camelCase property name fails the prepare, naming who declared it.
   *
   * The fixture is installed here rather than in $modules on purpose: it
   * carries a declaration every other assertion in this class would fail on.
   */
  public function testRefusesCamelCasePropertyNameAndNamesTheExtension(): void {
    $this->installTheme();
    $this->container->get('module_installer')->install(['neo_build_test_camel']);

    try {
      $this->preparer()->prepare('front');
      $this->fail('Expected prepare to fail on the camelCase property name.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('neo_build_test_camel', $e->getMessage());
      $this->assertStringContainsString('.neo-build-test-camel', $e->getMessage());
      $this->assertStringContainsString('borderRadius', $e->getMessage());
    }
  }

  /**
   * An array value fails the prepare, naming who declared it.
   */
  public function testRefusesAnArrayValueAndNamesTheExtension(): void {
    $this->installTheme();
    $this->container->get('module_installer')->install(['neo_build_test_nested']);

    try {
      $this->preparer()->prepare('front');
      $this->fail('Expected prepare to fail on the array value.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('neo_build_test_nested', $e->getMessage());
      $this->assertStringContainsString('.neo-build-test-nested', $e->getMessage());
      $this->assertStringContainsString('&:hover', $e->getMessage());
    }
  }

  /**
   * The refusal says what to do about it.
   *
   * The question the message will prompt is "then where does this rule go",
   * so the message answers it rather than leaving the reader to the README.
   */
  public function testTheRefusalCarriesTheRemedy(): void {
    $this->installTheme();
    $this->container->get('module_installer')->install(['neo_build_test_camel']);

    try {
      $this->preparer()->prepare('front');
      $this->fail('Expected prepare to fail on the camelCase property name.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('kebab-case', $e->getMessage());
      $this->assertStringContainsString('import entrypoint', $e->getMessage());
    }
  }

  /**
   * Prepare fails rather than completing with a notice.
   *
   * A warning is what produced the situation this rule undoes: a green build
   * shipping form controls with no styling. Nothing is written either.
   */
  public function testPrepareFailsRatherThanCompletingWithNotice(): void {
    $this->installTheme();
    $this->container->get('module_installer')->install(['neo_build_test_camel']);

    $threw = FALSE;
    try {
      $this->preparer()->prepare('front');
    }
    catch (\InvalidArgumentException $e) {
      $threw = TRUE;
    }

    $this->assertTrue($threw, 'Prepare must fail on a refused declaration.');
    $this->assertFileDoesNotExist($this->root . '/neo.json');
  }

}
