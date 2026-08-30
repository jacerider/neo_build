<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Core\Asset\LibraryDiscoveryParser;
use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\Extension\ModuleHandler;
use Drupal\Core\Extension\ThemeExtensionList;
use Drupal\Core\State\StateInterface;
use Drupal\Core\Theme\ThemeManagerInterface;
use Drupal\neo_build\DevServer;
use Drupal\neo_build\ManifestResolver;
use Drupal\neo_build\NeoBuild;
use Drupal\neo_build\NeoExtensionList;
use Drupal\neo_build\Preparer;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use PHPUnit\Framework\Attributes\Group;
use Psr\Log\LoggerInterface;

/**
 * Pins that NeoBuild owns its state by injection and holds nothing statically.
 *
 * The render path was unreachable from a test because every answer it needed
 * came from a static service locator or a static mutable flag. These tests are
 * the proof that it is reachable: each one constructs NeoBuild by hand, with
 * no container behind it except where a deprecated static wrapper is the thing
 * under test.
 */
#[Group('neo_build')]
class NeoBuildStateTest extends UnitTestCase {

  /**
   * The state the service under test reads and writes.
   */
  protected StateInterface $state;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();
    $this->state = $this->memoryState();
  }

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    // The static wrapper tests set a container; leaving it set leaks into every
    // later test in the process.
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::unsetContainer();
    parent::tearDown();
  }

  /**
   * Criterion: reads and writes dev mode and scope through injected state.
   *
   * There is deliberately no container in this test. Before the injection this
   * failed with "\Drupal::$container is not initialized yet", which is the
   * whole complaint the ticket makes.
   */
  public function testItReadsDevModeAndScopeThroughTheInjectedState(): void {
    $neoBuild = $this->neoBuild();

    $this->assertFalse($neoBuild->isDevMode(), 'Dev mode defaults to off.');
    $this->assertSame('front', $neoBuild->getScope(), 'The scope defaults to front.');

    $this->state->set(NeoBuild::DEV_STATE_KEY, TRUE);
    $this->state->set(NeoBuild::SCOPE_STATE_KEY, 'back');

    $this->assertTrue($neoBuild->isDevMode());
    $this->assertSame('back', $neoBuild->getScope());
  }

  /**
   * Criterion: reads and writes dev mode and scope through injected state.
   */
  public function testItWritesDevModeAndScopeThroughTheInjectedState(): void {
    $neoBuild = $this->neoBuild();

    $neoBuild->setDevMode(TRUE);
    $neoBuild->setScope('back');

    $this->assertTrue($this->state->get(NeoBuild::DEV_STATE_KEY));
    $this->assertSame('back', $this->state->get(NeoBuild::SCOPE_STATE_KEY));

    $neoBuild->setDevMode(FALSE);
    $this->assertNull($this->state->get(NeoBuild::DEV_STATE_KEY), 'Dev mode off deletes the key.');
    $this->assertFalse($neoBuild->isDevMode());
  }

  /**
   * Criterion: each state key is spelled once, as a shared constant.
   */
  public function testItSpellsEachStateKeyOnce(): void {
    $this->assertSame('neo.build.dev', NeoBuild::DEV_STATE_KEY);
    $this->assertSame('neo.build.scope', NeoBuild::SCOPE_STATE_KEY);

    $this->assertSame(
      NeoBuild::DEV_STATE_KEY,
      Preparer::DEV_STATE_KEY,
      'Preparer and NeoBuild must read one constant, not two spellings.',
    );
    $this->assertSame(NeoBuild::SCOPE_STATE_KEY, Preparer::SCOPE_STATE_KEY);
  }

  /**
   * Criterion: each state key is spelled once, as a shared constant.
   *
   * The constants are only one spelling if no source file writes the literal
   * beside them. This is the half a same-value assertion cannot see.
   */
  public function testNoSourceFileRepeatsTheStateKeyLiterals(): void {
    $occurrences = [];
    $directory = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator(dirname(__DIR__, 3) . '/src'),
    );
    foreach ($directory as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }
      $source = file_get_contents($file->getPathname());
      foreach (["'neo.build.dev'", "'neo.build.scope'", "'neo.build.'"] as $literal) {
        $count = substr_count($source, $literal);
        if ($count > 0) {
          $occurrences[$literal] = ($occurrences[$literal] ?? 0) + $count;
        }
      }
    }

    // The keys compose from one prefix constant, so no file writes a whole key
    // and exactly one file writes the prefix. Both halves matter: a second
    // prefix would reintroduce the drift, and an ad-hoc whole key would sit
    // beside the constants looking equivalent until one of them moved.
    $this->assertArrayNotHasKey(
      "'neo.build.dev'",
      $occurrences,
      'The DEV key belongs to NeoBuild::DEV_STATE_KEY, not to a literal.',
    );
    $this->assertArrayNotHasKey(
      "'neo.build.scope'",
      $occurrences,
      'The scope key belongs to NeoBuild::SCOPE_STATE_KEY, not to a literal.',
    );
    $this->assertSame(
      1,
      $occurrences["'neo.build.'"] ?? 0,
      'The key prefix is spelled exactly once, by NeoBuild::STATE_PREFIX.',
    );
  }

  /**
   * Criterion: the static helpers read and write the keys the accessors use.
   */
  public function testTheDeprecatedStaticHelpersUseTheSameKeys(): void {
    $this->setContainerWithState();
    $neoBuild = $this->neoBuild();

    // These wrappers are deprecated and calling them is the point: the
    // criterion is that they still read and write the keys the injected
    // accessors use. Nothing else may call them.
    // @phpstan-ignore staticMethod.deprecated
    NeoBuild::setNeoState('dev', TRUE);
    // @phpstan-ignore staticMethod.deprecated
    NeoBuild::setNeoState('scope', 'back');

    $this->assertTrue($neoBuild->isDevMode(), 'The static setter is visible to the injected reader.');
    $this->assertSame('back', $neoBuild->getScope());
    // @phpstan-ignore staticMethod.deprecated
    $this->assertTrue(NeoBuild::getNeoState('dev'));

    // @phpstan-ignore staticMethod.deprecated
    NeoBuild::unsetNeoState('dev');
    $this->assertFalse($neoBuild->isDevMode(), 'The static unsetter is visible to the injected reader.');
  }

  /**
   * Criterion: the static helpers emit no runtime deprecation notice.
   */
  public function testTheStaticHelpersEmitNoRuntimeDeprecation(): void {
    $this->setContainerWithState();

    $deprecations = [];
    set_error_handler(
      function (int $errno, string $errstr) use (&$deprecations): bool {
        $deprecations[] = $errstr;
        return TRUE;
      },
      E_USER_DEPRECATED,
    );
    try {
      // @phpstan-ignore staticMethod.deprecated
      NeoBuild::setNeoState('dev', TRUE);
      // @phpstan-ignore staticMethod.deprecated
      NeoBuild::getNeoState('dev');
      // @phpstan-ignore staticMethod.deprecated
      NeoBuild::unsetNeoState('dev');
    }
    finally {
      restore_error_handler();
    }

    $this->assertSame([], $deprecations, 'No runtime deprecation may reach sites that cannot act on it.');
  }

  /**
   * Criterion: the static helpers are deprecated, in the docblock.
   *
   * The other half of the rule above: silent at runtime, but marked, or the
   * wrappers read as supported API rather than as a migration path.
   */
  public function testTheStaticHelpersCarryDocblockDeprecation(): void {
    foreach (['getNeoState', 'setNeoState', 'unsetNeoState'] as $method) {
      $docComment = (new \ReflectionMethod(NeoBuild::class, $method))->getDocComment();
      $this->assertIsString($docComment, sprintf('%s() has no docblock.', $method));
      $this->assertStringContainsString(
        '@deprecated',
        $docComment,
        sprintf('%s() is not marked deprecated.', $method),
      );
    }
  }

  /**
   * Criterion: preventAlter, set through the service, suppresses the rewrite.
   */
  public function testPreventAlterSuppressesTheLibraryRewrite(): void {
    $neoBuild = $this->neoBuild();
    $libraries = ['some_library' => ['css' => [], 'js' => []]];
    $untouched = $libraries;

    $neoBuild->preventAlter();
    $neoBuild->processLibraries($libraries, 'neo_build_test');

    $this->assertSame($untouched, $libraries, 'Nothing may be rewritten while the flag is set.');
  }

  /**
   * Criterion: preventAlter is an instance flag, not a static one.
   *
   * Two services must be able to disagree, which is what proves the flag is not
   * process-global any more.
   */
  public function testPreventAlterIsPerInstance(): void {
    $prevented = $this->neoBuild();
    $other = $this->neoBuild();

    $prevented->preventAlter();

    $this->assertTrue($prevented->isAlterPrevented());
    $this->assertFalse($other->isAlterPrevented(), 'The flag leaked across instances.');

    $prevented->preventAlter(FALSE);
    $this->assertFalse($prevented->isAlterPrevented());
  }

  /**
   * Criterion: NeoBuild declares no static mutable property.
   */
  public function testNeoBuildDeclaresNoStaticMutableProperty(): void {
    $static = [];
    foreach ((new \ReflectionClass(NeoBuild::class))->getProperties() as $property) {
      if ($property->isStatic() && !$property->isReadOnly()) {
        $static[] = $property->getName();
      }
    }

    $this->assertSame([], $static, 'Static mutable state is what made the render path untestable.');
  }

  /**
   * Builds the service under test over the in-memory state.
   */
  protected function neoBuild(): NeoBuild {
    // NeoExtensionList is final, so it is built over doubled collaborators
    // rather than doubled itself. Nothing in these tests reaches it: the
    // preventAlter guard returns first.
    $neoExtensionList = new NeoExtensionList(
      $this->createMock(ModuleHandler::class),
      $this->createMock(ModuleExtensionList::class),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(LibraryDiscoveryParser::class),
    );

    // ManifestResolver is final too, and the preventAlter guard means nothing
    // here ever asks it anything.
    $manifestResolver = new ManifestResolver(
      $this->createMock(ThemeManagerInterface::class),
      $this->createMock(ThemeExtensionList::class),
      $this->createMock(ConfigFactoryInterface::class),
      $this->createMock(LoggerInterface::class),
      sys_get_temp_dir(),
    );

    return new NeoBuild(
      $neoExtensionList,
      $this->state,
      $manifestResolver,
      new DevServer($this->createMock(ClientInterface::class), 5173),
    );
  }

  /**
   * Puts the in-memory state behind \Drupal::state() for the wrapper tests.
   */
  protected function setContainerWithState(): void {
    $container = new ContainerBuilder();
    $container->set('state', $this->state);
    // phpcs:ignore DrupalPractice.Objects.GlobalDrupal.GlobalDrupal
    \Drupal::setContainer($container);
  }

  /**
   * An in-memory state, so a unit test can watch reads and writes land.
   */
  protected function memoryState(): StateInterface {
    return new class() implements StateInterface {

      /**
       * The stored values.
       */
      protected array $values = [];

      /**
       * {@inheritdoc}
       */
      public function get($key, $default = NULL) {
        return $this->values[$key] ?? $default;
      }

      /**
       * {@inheritdoc}
       */
      public function getMultiple(array $keys) {
        $found = [];
        foreach ($keys as $key) {
          if (array_key_exists($key, $this->values)) {
            $found[$key] = $this->values[$key];
          }
        }
        return $found;
      }

      /**
       * {@inheritdoc}
       */
      public function set($key, $value) {
        $this->values[$key] = $value;
      }

      /**
       * {@inheritdoc}
       */
      public function setMultiple(array $data) {
        foreach ($data as $key => $value) {
          $this->values[$key] = $value;
        }
      }

      /**
       * {@inheritdoc}
       */
      public function delete($key) {
        unset($this->values[$key]);
      }

      /**
       * {@inheritdoc}
       */
      public function deleteMultiple(array $keys) {
        foreach ($keys as $key) {
          unset($this->values[$key]);
        }
      }

      /**
       * {@inheritdoc}
       */
      public function resetCache() {
      }

      /**
       * {@inheritdoc}
       */
      public function getValuesSetDuringRequest(string $key): ?array {
        return NULL;
      }

    };
  }

}
