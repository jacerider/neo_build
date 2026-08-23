<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Generator\TailwindStylesheet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * The flat declaration rule, enforced at the one gate every rule passes.
 *
 * Tailwind data in an info file is flat: kebab-case property names, plus the
 * `apply` key. Two forms are refused — a property name carrying an uppercase
 * letter, and an array value, which is how a nested selector used to be
 * written. Both are refused at `addRule()`, so info-file data and build event
 * subscriber data are held to the same rule.
 *
 * These live beside TailwindStylesheetCharacterisationTest rather than in it.
 * That suite excludes camelCase and nesting by construction so that it can
 * survive the removal unedited, and an addition here is what keeps it able to.
 */
#[Group('neo_build')]
class TailwindStylesheetFlatDeclarationsTest extends UnitTestCase {

  /**
   * A property name carrying an uppercase letter is refused.
   */
  public function testRejectsCamelCasePropertyName(): void {
    $css = new TailwindStylesheet();

    $this->expectException(\InvalidArgumentException::class);
    $css->addRule('.btn', ['borderRadius' => '4px']);
  }

  /**
   * An array value — the old nested selector form — is refused.
   */
  public function testRejectsArrayPropertyValue(): void {
    $css = new TailwindStylesheet();

    $this->expectException(\InvalidArgumentException::class);
    $css->addRule('.btn', ['&:hover' => ['color' => 'red']]);
  }

  /**
   * The message names the selector and the key, the two things a caller needs.
   *
   * The extension is not knowable here; naming it is the preparer's job.
   */
  public function testMessageNamesSelectorAndKey(): void {
    $css = new TailwindStylesheet();

    try {
      $css->addRule('.form-label', ['fontWeight' => '700']);
      $this->fail('Expected an \InvalidArgumentException.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('.form-label', $e->getMessage());
      $this->assertStringContainsString('fontWeight', $e->getMessage());
    }

    try {
      $css->addRule('.form-item-border', ['&:focus' => ['color' => 'red']]);
      $this->fail('Expected an \InvalidArgumentException.');
    }
    catch (\InvalidArgumentException $e) {
      $this->assertStringContainsString('.form-item-border', $e->getMessage());
      $this->assertStringContainsString('&:focus', $e->getMessage());
    }
  }

  /**
   * A custom property keeps its case: `--` names are the caller's business.
   *
   * Custom properties are case-sensitive, so `--tw-Ring` and `--tw-ring` are
   * two different properties and neither is a mistake.
   */
  public function testAcceptsCustomPropertyWithUppercaseLetter(): void {
    $css = new TailwindStylesheet();
    $css->addRule('.ring', ['--tw-Ring-Shadow' => '0 0 0 1px red']);

    $this->assertStringContainsString('--tw-Ring-Shadow: 0 0 0 1px red;', $css->toCss());
  }

  /**
   * A vendor-prefixed name passes: it is kebab-case with a leading dash.
   */
  public function testAcceptsVendorPrefixedPropertyName(): void {
    $css = new TailwindStylesheet();
    $css->addRule('.smooth', ['-webkit-font-smoothing' => 'antialiased']);

    $this->assertStringContainsString('-webkit-font-smoothing: antialiased;', $css->toCss());
  }

  /**
   * The refusal covers utilities too — addUtility() routes through addRule().
   */
  public function testRefusalCoversUtilities(): void {
    $css = new TailwindStylesheet();

    $this->expectException(\InvalidArgumentException::class);
    $css->addUtility('.badge', ['fontSize' => '10px']);
  }

  /**
   * Flat data still passes, `apply` included.
   */
  public function testAcceptsFlatDeclarations(): void {
    $css = new TailwindStylesheet();
    $css->addUtility('.card', ['apply' => '@apply block rounded-sm border']);
    $css->addRule('.text-md', ['font-size' => 'var(--text-md)', 'line-height' => '1.5rem']);

    $out = $css->toCss();
    $this->assertStringContainsString('@apply block rounded-sm border;', $out);
    $this->assertStringContainsString('font-size: var(--text-md);', $out);
  }

}
