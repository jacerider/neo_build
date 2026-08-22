<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Generator\TsConfigGenerator;
use Drupal\neo_build\NeoBuildCollection;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests the neo.tsconfig.json artifact generator.
 *
 * The artifact is the TypeScript include list: neo_build's own typings first,
 * then every TS entrypoint and typings directory in the order the collection
 * received them.
 */
#[Group('neo_build')]
class TsConfigGeneratorTest extends UnitTestCase {

  /**
   * The module typings come first; entrypoints follow in collection order.
   */
  public function testWritesModuleTypingsFirstThenEntrypointsInCollectionOrder(): void {
    $collection = new NeoBuildCollection(
      'wps.ddev.site',
      5173,
      TRUE,
      FALSE,
      '/var/www/html',
      'web',
      'modules/contrib/neo_build',
    );
    $collection->addTsInclude('themes/front/src/js/main.ts');
    $collection->addTsInclude('themes/front/src/js/typings/*.d.ts');
    $collection->addTsInclude('modules/contrib/neo_modal/src/js/modal.ts');

    $artifact = (new TsConfigGenerator())->generate($collection);

    $this->assertSame('/var/www/html/neo.tsconfig.json', $artifact->getDestination());
    $data = json_decode($artifact->getContent(), TRUE);
    $this->assertSame([
      'include' => [
        './web/modules/contrib/neo_build/src/js/typings/*.d.ts',
        './web/themes/front/src/js/main.ts',
        './web/themes/front/src/js/typings/*.d.ts',
        './web/modules/contrib/neo_modal/src/js/modal.ts',
      ],
    ], $data);
    // Pretty-printed, exactly as before.
    $this->assertSame(json_encode($data, JSON_PRETTY_PRINT), $artifact->getContent());
  }

}
