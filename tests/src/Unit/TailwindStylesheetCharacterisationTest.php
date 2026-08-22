<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Generator\TailwindStylesheet;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Characterises the bytes TailwindStylesheet emits, before anything is removed.
 *
 * TailwindStylesheet renders the body of every scope's tailwind.neo.css — the
 * file Tailwind compiles for every site running neo_build — and until this
 * suite existed nothing asserted a byte of it. These tests describe the class
 * exactly as it stands so that each later removal is proven inert rather than
 * assumed so.
 *
 * They are written to survive the whole plan **unedited**. From the first
 * removal onward, "the characterisation suite passes unedited" is the proof
 * that the removal was behaviour-preserving. Nothing here exercises a
 * capability the plan removes — no media queries, no base or utilities layer,
 * no camelCase property names, no nested selectors, no minify — because a test
 * that has to be *changed* to keep passing is a behaviour change wearing a
 * green tick. A test covering a capability that is going away is deleted whole
 * in the same commit as the capability; there are none of those here by
 * construction.
 *
 * Every expectation is a literal of the real output, not a shape assertion.
 * Blank lines and section separators are part of the artifact: `git diff` on a
 * 9,800-line generated stylesheet cannot tell a moved blank line from a moved
 * rule, so the bytes are pinned here where the failure names itself.
 */
#[Group('neo_build')]
class TailwindStylesheetCharacterisationTest extends UnitTestCase {

  /**
   * The sections come out in one fixed order, with imports last.
   *
   * Sources, the theme block, top-level rules, the components layer, variants,
   * then imports. The `@import` position is the surprising one and it is
   * deliberate: the imported files carry `@utility` and `@custom-variant`
   * definitions that have to be able to override the generated ones above
   * them, so they are emitted after, not before.
   *
   * The separators are pinned with the order. Note that no blank line
   * separates the last top-level rule from `@layer components` — every other
   * seam has one.
   */
  public function testEmitsSectionsInOrderWithImportsLast(): void {
    $css = new TailwindStylesheet();
    $css->addImport('/a/_utils.css');
    $css->addSource('/src/**/*.twig');
    $css->addCssVariable('--color-brand', 'red');
    $css->addUtility('.text-balance', ['text-wrap' => 'balance']);
    $css->addRule('.card', ['padding' => '1rem'], 'components');
    $css->addVariant('hocus', ['&:hover', '&:focus']);

    $expected = <<<'CSS'
    /* Sources */
    @source "/src/**/*.twig";

    @theme {
      --color-brand: red;
    }

    @utility text-balance {
      text-wrap: balance;
    }
    @layer components {
      .card {
        padding: 1rem;
      }
    }

    /* Variants */
    @custom-variant hocus (&:hover, &:focus);

    /* Imports */
    @import "/a/_utils.css";
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * The theme block keeps insertion order, prefixes `--`, and never doubles.
   *
   * Insertion order, not sorted order: the caller decides the sequence and the
   * stylesheet preserves it. A name arriving without the `--` prefix gets one.
   * A value that already ends in `;` does not get a second one.
   */
  public function testRendersThemeBlockInInsertionOrderPrefixingAndNotDoublingSemicolons(): void {
    $css = new TailwindStylesheet();
    $css->addCssVariable('--z', '1');
    $css->addCssVariable('a-plain', '2');
    $css->addCssVariable('--semi', '3;');

    $expected = <<<'CSS'
    @theme {
      --z: 1;
      --a-plain: 2;
      --semi: 3;
    }
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * Top-level rules are unwrapped, unsorted, and in insertion order.
   *
   * This is where every `@utility` lands, and it is not a legacy path: Tailwind
   * 4 requires `@utility` to sit outside any layer. Unlike the components
   * layer, nothing sorts this bucket — `.zzz` stays ahead of `.aaa`.
   *
   * An `apply` property is the one property rendered without its name: it
   * comes out as a bare `@apply …;` line rather than `apply: @apply …;`.
   */
  public function testEmitsTopLevelRulesUnsortedInInsertionOrderWithBareApply(): void {
    $css = new TailwindStylesheet();
    $css->addUtility('.zzz', ['color' => 'red']);
    $css->addUtility('.aaa', ['apply' => '@apply block;']);
    $css->addRule('.mmm', ['font-size' => '1rem']);

    $expected = <<<'CSS'
    @utility zzz {
      color: red;
    }
    @utility aaa {
      @apply block;
    }
    .mmm {
      font-size: 1rem;
    }
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * The components layer is ordered by the comparator, not by insertion.
   *
   * Two things are pinned. Rules carrying an `apply` property weigh 1000 and
   * sort after every rule that does not, so `.mid` lands last despite being
   * added third. And for equal weights the tie-break is the selector string
   * ascending, which is why `.zeta` — added first — comes out third.
   *
   * The equal-weight order is pinned deliberately: it is the part a future
   * change to the comparator would move silently, and moving it would reorder
   * every components rule on every site.
   */
  public function testOrdersComponentsLayerByWeightThenSelector(): void {
    $css = new TailwindStylesheet();
    $css->addRule('.zeta', ['color' => 'z'], 'components');
    $css->addRule('.alpha', ['color' => 'a'], 'components');
    $css->addRule('.mid', ['apply' => '@apply alpha;'], 'components');
    $css->addRule('.beta', ['color' => 'b'], 'components');

    $expected = <<<'CSS'
    @layer components {
      .alpha {
        color: a;
      }
      .beta {
        color: b;
      }
      .zeta {
        color: z;
      }
      .mid {
        @apply alpha;
      }
    }
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * A custom variant renders from one selector and from several.
   *
   * A bare string and a list produce the same shape; several selectors are
   * joined with ", " inside the parentheses.
   */
  public function testRendersCustomVariantsFromOneSelectorAndFromSeveral(): void {
    $css = new TailwindStylesheet();
    $css->addVariant('one', '&:hover');
    $css->addVariant('many', ['.a &', '&.b']);

    $expected = <<<'CSS'
    /* Variants */
    @custom-variant one (&:hover);
    @custom-variant many (.a &, &.b);
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * Sources and imports de-duplicate, and quoting is applied exactly once.
   *
   * The same path added twice appears once. A path that arrives already quoted
   * is not quoted again, so both spellings of the same input converge on one
   * output. `addImports()` accepts a bare string or an array carrying a media
   * query, and the media query trails the quoted URL.
   *
   * The absence of a blank line between the two sections is also pinned: with
   * no theme, rules or layers between them, nothing separates sources from
   * imports.
   */
  public function testDeduplicatesSourcesAndImportsAndQuotesEachOnce(): void {
    $css = new TailwindStylesheet();
    $css->addSource('/x.twig');
    $css->addSource('/x.twig');
    $css->addSource('"/y.twig"');
    $css->addImport('/i.css');
    $css->addImport('/i.css');
    $css->addImports(['/j.css', ['url' => '/k.css', 'media' => 'screen']]);

    $expected = <<<'CSS'
    /* Sources */
    @source "/x.twig";
    @source "/y.twig";
    /* Imports */
    @import "/i.css";
    @import "/j.css";
    @import "/k.css" screen;
    CSS;

    $this->assertSame($expected, $css->toCss());
  }

  /**
   * Empty input emits the empty string — no stray section, no whitespace.
   *
   * Every section is guarded, so nothing renders its comment header with
   * nothing under it, and the whole output is trimmed. A generator that
   * emitted a lone "/* Sources *\/" or a trailing newline here would put a
   * spurious diff into every site's committed stylesheet.
   */
  public function testEmitsNothingAtAllForEmptyInput(): void {
    $css = new TailwindStylesheet();

    $this->assertSame('', $css->toCss());
  }

  /**
   * The rendered output never carries leading or trailing whitespace.
   *
   * Guards the `trim()` at the end of the render, which is what keeps the
   * blank line after the variants section from reaching the file.
   */
  public function testNeverEmitsLeadingOrTrailingWhitespace(): void {
    $css = new TailwindStylesheet();
    $css->addSource('/src/**/*.twig');
    $css->addVariant('hocus', ['&:hover']);

    $rendered = $css->toCss();

    $this->assertSame(trim($rendered), $rendered);
  }

}
