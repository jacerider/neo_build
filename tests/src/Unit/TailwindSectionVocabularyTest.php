<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Core\Extension\Extension;
use Drupal\neo_build\NeoExtension;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the closed vocabulary of Tailwind sections and its explicit dispatch.
 *
 * An extension's `neo:` block offers four sections that reach the collection —
 * theme, components, utilities, variants — and one retired section, base,
 * which reaches nothing. The extension states both lists and decides nothing
 * about either: it reports which retired keys an author actually wrote, and
 * the preparer turns those into notices.
 *
 * The dispatch is the durable half. It used to build a method name from the
 * key and test it with `method_exists()`, so removing a collection method
 * turned a condition false and the section silently stopped arriving. Four
 * named calls make the same removal a call site that cannot compile.
 */
#[Group('neo_build')]
class TailwindSectionVocabularyTest extends UnitTestCase {

  /**
   * The package root.
   */
  protected function packageRoot(): string {
    return dirname(__DIR__, 3);
  }

  /**
   * Builds a Neo extension over the given `neo:` info block.
   *
   * Extension checks its info file is really there, so the module's own stands
   * in for one; only the info array it is handed decides anything here.
   */
  protected function extension(array $neo): NeoExtension {
    $extension = new Extension($this->packageRoot(), 'module', 'neo_build.info.yml');
    $extension->info = ['name' => 'Example', 'type' => 'module', 'neo' => $neo];
    return new NeoExtension($extension);
  }

  /**
   * The four consumed sections come back filled, and base is not among them.
   */
  public function testReturnsTheFourConsumedSectionsAndNoBase(): void {
    $info = $this->extension([])->getTailwindInfo();

    $this->assertSame(['theme', 'components', 'utilities', 'variants'], array_keys($info));
    $this->assertSame([[], [], [], []], array_values($info));
  }

  /**
   * A declared base block is still dropped from the consumed sections.
   */
  public function testTheRetiredSectionNeverReachesTheConsumedSections(): void {
    $info = $this->extension(['base' => ['.a' => ['color' => 'red']]])->getTailwindInfo();

    $this->assertArrayNotHasKey('base', $info);
  }

  /**
   * The extension reports the retired sections its author actually declared.
   */
  public function testReportsTheRetiredSectionsTheExtensionDeclares(): void {
    $extension = $this->extension(['base' => ['.a' => ['color' => 'red']]]);

    $this->assertSame(['base'], $extension->getRetiredTailwindSections());
  }

  /**
   * An extension declaring none reports none, which is the normal case.
   */
  public function testReportsNoRetiredSectionsWhenNoneAreDeclared(): void {
    $this->assertSame([], $this->extension([])->getRetiredTailwindSections());
    $this->assertSame([], $this->extension(['theme' => ['a' => 'b']])->getRetiredTailwindSections());
  }

  /**
   * No source file dispatches a collection method through method_exists().
   *
   * The whole point of the explicit calls, and the property is "this construct
   * is not in the module" rather than "this class behaves", so it is checked
   * over the source. Tokenised rather than grepped: the prose explaining why
   * the construct is gone names it, and a comment is not a dispatch.
   */
  public function testNoSourceFileDispatchesCollectionMethodsByName(): void {
    $offenders = [];
    $files = new \RecursiveIteratorIterator(
      new \RecursiveDirectoryIterator($this->packageRoot() . '/src', \FilesystemIterator::SKIP_DOTS),
    );

    foreach ($files as $file) {
      if ($file->getExtension() !== 'php') {
        continue;
      }

      foreach (token_get_all((string) file_get_contents($file->getPathname())) as $token) {
        if (is_array($token) && $token[0] === T_STRING && $token[1] === 'method_exists') {
          $offenders[] = $file->getPathname() . ':' . $token[2];
        }
      }
    }

    $this->assertSame([], $offenders, 'A source file still dispatches by built method name.');
  }

}
