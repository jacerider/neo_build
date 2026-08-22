<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Generator\NeoJsonGenerator;
use Drupal\neo_build\NeoBuildCollection;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the neo.json artifact generator.
 *
 * The Node side — the Vite config and the Neo Tailwind plugin — reads
 * neo.json. It used to be serialised from the collection *after* the
 * CSS build had drained the collection of everything the stylesheet owns, and
 * that ordering accident is now the partition rule: the CSS-owned Tailwind
 * keys are emitted empty, the theme is emitted without its `--` variables,
 * and the collection itself is left untouched.
 */
#[Group('neo_build')]
class NeoJsonGeneratorTest extends UnitTestCase {

  /**
   * Builds a collection rooted at a known project root and docroot.
   *
   * @param bool $dev
   *   Whether the collection is a DEV build; outside DEV the scope is forced
   *   to front, so the scope assertion runs in DEV.
   */
  protected function collection(bool $dev = FALSE): NeoBuildCollection {
    return new NeoBuildCollection(
      'wps.ddev.site',
      5173,
      TRUE,
      $dev,
      '/var/www/html',
      'web',
      'modules/contrib/neo_build',
    );
  }

  /**
   * The CSS-owned keys are emitted empty and `--` theme keys are omitted.
   */
  public function testLeavesCssOwnedKeysEmptyAndOmitsVariableThemeKeys(): void {
    $collection = $this->collection();
    $collection->addTailwindSource('front:Files', 'themes/front/src/**/*.twig');
    $collection->addTailwindImport('neo:base', 'modules/contrib/neo/src/css/base.css');
    $collection->addTailwindBase(['html' => ['color' => 'red']]);
    $collection->addTailwindComponents(['.card' => ['padding' => '1rem']]);
    $collection->addTailwindUtility('.text-balance', ['text-wrap' => 'balance']);
    $collection->addTailwindVariants(['hocus' => ['&:hover', '&:focus']]);
    $collection->addTailwindThemeItem('--color-brand', 'red');
    $collection->addTailwindTheme(['extend' => ['colors' => ['brand' => 'var(--color-brand)']]]);

    $data = json_decode((new NeoJsonGenerator())->generate($collection)->getContent(), TRUE);

    foreach (['source', 'import', 'base', 'components', 'utilities', 'variants'] as $key) {
      $this->assertSame([], $data['tailwind'][$key], "tailwind.$key is emitted empty");
    }
    $this->assertArrayNotHasKey('--color-brand', $data['tailwind']['theme']);
    $this->assertSame('var(--color-brand)', $data['tailwind']['theme']['extend']['colors']['brand']);
    // Nothing was set, so nothing is emitted — as null, the way it always was.
    $this->assertNull($data['primaryRoot']);
    $this->assertNull($data['primaryFile']);

    // Read-only: the collection still holds everything the CSS artifact needs.
    $this->assertSame(['front:Files' => 'themes/front/src/**/*.twig'], $collection->getTailwindSources());
    $this->assertSame(['neo:base' => 'modules/contrib/neo/src/css/base.css'], $collection->getTailwindImports());
    $this->assertArrayHasKey('html', $collection->getTailwindBase());
    $this->assertArrayHasKey('.card', $collection->getTailwindComponents());
    $this->assertArrayHasKey('.text-balance', $collection->getTailwindUtilities());
    $this->assertArrayHasKey('hocus', $collection->getTailwindVariants());
    $this->assertSame('red', $collection->getTailwindTheme()['--color-brand']);
  }

  /**
   * List-valued keys are re-indexed; flags, roots and scope are carried as is.
   */
  public function testReindexesListValuedKeysAndCarriesFlagsRootsAndScopeAsGiven(): void {
    $collection = $this->collection(TRUE);
    $collection->setScope('back');
    $collection->setPrimaryRoot('themes/back');
    $collection->setPrimaryFile('themes/back/src/css/main.css');
    $collection->addTailwindIcon('home', 'lucide', 'e900');
    $collection->addTailwindIcon('menu', 'lucide', 'e901');
    $collection->addViteLib('front:main:Css', 'themes/front/src/css/main.css');
    $collection->addViteLib('front:main:Js', 'themes/front/src/js/main.ts');
    $collection->addStylelint('front:main', 'themes/front/src/css/main.css');

    $artifact = (new NeoJsonGenerator())->generate($collection);
    $this->assertSame('/var/www/html/neo.json', $artifact->getDestination());
    $content = $artifact->getContent();
    $data = json_decode($content, TRUE);

    $this->assertSame([
      'host', 'port', 'https', 'dev', 'root', 'docRoot', 'neoRoot', 'primaryRoot',
      'primaryFile', 'scope', 'ignored', 'tailwind', 'vite', 'stylelint',
    ], array_keys($data));
    $this->assertSame([
      'source', 'import', 'base', 'theme', 'utilities', 'components', 'variants',
      'icon_libraries', 'icons',
    ], array_keys($data['tailwind']));

    $this->assertSame('wps.ddev.site', $data['host']);
    $this->assertSame(5173, $data['port']);
    $this->assertTrue($data['https']);
    $this->assertTrue($data['dev']);
    $this->assertSame('/var/www/html/', $data['root']);
    $this->assertSame('web/', $data['docRoot']);
    $this->assertSame('modules/contrib/neo_build/', $data['neoRoot']);
    $this->assertSame('themes/back', $data['primaryRoot']);
    $this->assertSame('themes/back/src/css/main.css', $data['primaryFile']);
    $this->assertSame('back', $data['scope']);
    $this->assertSame(['**/.ddev/**/*', '**/web/core/**/*', '**/web/profiles/**/*', '**/web/sites/**/*'], $data['ignored']);

    $this->assertSame(['lucide'], $data['tailwind']['icon_libraries']);
    $this->assertSame(['home' => 'e900', 'menu' => 'e901'], $data['tailwind']['icons']);
    $this->assertSame([
      './themes/front/src/css/main.css',
      './themes/front/src/js/main.ts',
    ], $data['vite']['lib']);
    $this->assertSame(
      ['./web/themes/front/src/css/main.css'],
      $data['stylelint'],
    );

    // Pretty-printed, as before: the Node side reads it, people diff it.
    $this->assertSame(json_encode($data, JSON_PRETTY_PRINT), $content);
  }

}
