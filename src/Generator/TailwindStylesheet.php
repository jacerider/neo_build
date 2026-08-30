<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

/**
 * Composes and renders the body of a scope's tailwind.neo.css.
 *
 * One artifact, one caller: TailwindCssGenerator, which sits beside this class
 * and wraps the rendered body in the file header and the @plugin line.
 *
 * ## Emit order
 *
 * Fixed, and pinned byte-for-byte by TailwindStylesheetCharacterisationTest:
 *
 * 1. `/* Sources *\/` — the `@source` lines.
 * 2. The `@theme` block — every custom property, and the only place one goes.
 * 3. Top-level rules, unsorted, in insertion order.
 * 4. `@layer components` — the one layer, its rules in comparator order.
 * 5. `/* Variants *\/` — the `@custom-variant` lines.
 * 6. `/* Imports *\/` — the `@import` lines. Last. See below.
 *
 * Every `@utility` lands in the top-level bucket, and that is a requirement
 * rather than an oversight: Tailwind 4 does not register an `@utility` that
 * sits inside a layer. The bucket is also deliberately unsorted — Tailwind
 * resolves `@utility` cross-references from its own registry, not by file
 * order.
 *
 * ## Why `@import` comes last
 *
 * The CSS specification requires `@import` to precede every rule, so a reader
 * who knows CSS will read the trailing block as a bug and move it. It is not a
 * bug, and moving it breaks some of the sites that run this module.
 *
 * This artifact is never served to a browser. Vite and Lightning CSS inline
 * each import at the position it appears, so the specification's rule buys
 * nothing here and the position is free to mean something else. It means
 * **theme override precedence**: an imported stylesheet is inlined *after* the
 * generated `@theme` block, so a token a theme declares beats the same token
 * declared by a module's build-event subscriber. Move the block to the top and
 * that inverts, silently — no gate catches it, because twelve of the fourteen
 * generated stylesheets have no colliding declaration at all. The two that do
 * are both `front` stylesheets: each declares `--text-color-default` in both
 * places, and one also declares `@utility page-title` in both.
 *
 * Recorded in full, with the collision scan and the two rejected alternatives,
 * in the architecture decision record "The Tailwind stylesheet emits @import
 * last". It is named rather than linked on purpose: the docs directory is not
 * distributed with this module, so a path here would open nothing on the site
 * you are reading this on.
 *
 * ## The flat declaration rule
 *
 * A rule's properties are flat: kebab-case property names, plus the `apply`
 * key. `addRule()` refuses two forms outright, and `assertFlatDeclarations()`
 * is the one place that decides:
 *
 * - A property name carrying an uppercase letter. There is no camelCase
 *   conversion; the name reaches the emitted CSS verbatim. A name beginning
 *   `--` is exempt, because custom properties are case-sensitive and their
 *   case is the caller's business.
 * - An array value, which is how a nested selector used to be written.
 *
 * A rule needing a nested selector, a state or a pseudo-element belongs in an
 * import entrypoint — a stylesheet declared by a library flagged
 * `neo: { import: true }` — where it is written as ordinary CSS. That is where
 * every such rule now lives; nothing declares one in an info file.
 *
 * ## Basic usage
 *
 * $css = new TailwindStylesheet();
 *
 * // Add theme variables. They land in the @theme block.
 * $css->addCssVariable('--bg-site', '100px')
 *     ->addCssVariable('--primary-color', '#007bff');
 *
 * // Add rules. Property names are kebab-case and are emitted verbatim.
 * $css->addRule('.btn', [
 *     'display' => 'inline-block',
 *     'border-radius' => '4px',
 *     'background-color' => 'var(--primary-color)',
 *     ], 'components')
 * // With no layer, the rule is emitted at the top level.
 * ->addUtility('.text-center', ['text-align' => 'center']);
 *
 * // The `apply` key emits its value as written.
 * $css->addUtility('.card', ['apply' => '@apply block rounded-sm border']);
 *
 * echo $css->toCss();
 */
class TailwindStylesheet {

  /**
   * Indentation for one nesting level of emitted CSS.
   */
  private const INDENT = '  ';

  /**
   * Weight given to a layered rule whose properties carry an `apply` entry.
   *
   * The field is kept rather than removed, and this is what can set it:
   * `addRule()`, and only when the properties array has an `apply` key. It is
   * the sole weight anything in this class writes.
   *
   * Its effect is to sort such rules after every rule that does not carry one,
   * within the same layer. No subscriber puts an `apply` rule in a layer
   * today — every `apply` in the info.yml `neo:` blocks sits under
   * `utilities:`, which becomes a top-level `@utility` and is never sorted —
   * so on today's sites every layered rule weighs 0 and the order is by
   * selector alone. The field survives because the path that sets it is a
   * supported one for a subscriber to take, and it is pinned by a test.
   */
  private const APPLY_WEIGHT = 1000;

  /**
   * The only layer this stylesheet emits.
   *
   * Tailwind declares its own layer order, and of its layers only `components`
   * carries anything here. Everything else a caller adds is a top-level rule:
   * `@utility` in particular has to sit outside any layer for Tailwind 4 to
   * register it.
   */
  private const LAYER = 'components';

  /**
   * List of @import statements.
   *
   * @var array
   */
  private array $imports = [];

  /**
   * List of source files.
   *
   * @var array
   */
  private array $sources = [];

  /**
   * CSS variables (custom properties).
   *
   * @var array
   */
  private array $cssVariables = [];

  /**
   * Rules belonging to the components layer.
   *
   * @var array
   */
  private array $components = [];

  /**
   * List of CSS rules without layers (for backward compatibility).
   *
   * @var array
   */
  private array $rules = [];

  /**
   * List of variants for components.
   *
   * @var array
   */
  private array $variants = [];

  /**
   * Add an @import statement.
   *
   * @param string $url
   *   URL or path to import.
   * @param string|null $media
   *   Optional media query for the import.
   */
  public function addImport(string $url, ?string $media = NULL): self {
    // Ensure URL is properly quoted if it's not already.
    if (!preg_match('/^(url\(|["\'])/', $url)) {
      $url = '"' . $url . '"';
    }

    $import = '@import ' . $url;

    if ($media) {
      $import .= ' ' . $media;
    }

    $import .= ';';

    // Avoid duplicate imports.
    if (!in_array($import, $this->imports)) {
      $this->imports[] = $import;
    }

    return $this;
  }

  /**
   * Add multiple @import statements.
   *
   * @param array $imports
   *   Array of imports, each can be string or array with 'url' and optional
   *   'media'.
   */
  public function addImports(array $imports): self {
    foreach ($imports as $import) {
      if (is_string($import)) {
        $this->addImport($import);
      }
      elseif (is_array($import) && isset($import['url'])) {
        $this->addImport($import['url'], $import['media'] ?? NULL);
      }
    }

    return $this;
  }

  /**
   * Add a source file.
   *
   * @param string $url
   *   URL or path to the source file.
   */
  public function addSource(string $url): self {
    // Ensure URL is properly quoted if it's not already.
    if (!preg_match('/^(url\(|["\'])/', $url)) {
      $url = '"' . $url . '"';
    }

    $source = '@source ' . $url . ';';

    // Avoid duplicate imports.
    if (!in_array($source, $this->sources)) {
      $this->sources[] = $source;
    }

    return $this;
  }

  /**
   * Add multiple source files.
   *
   * @param array $sources
   *   Array of source URLs.
   */
  public function addSources(array $sources): self {
    foreach ($sources as $source) {
      $this->addSource($source);
    }

    return $this;
  }

  /**
   * Add a CSS custom property (variable) to the theme.
   *
   * @param string $name
   *   The variable name (should start with --).
   * @param string $value
   *   The variable value.
   */
  public function addCssVariable(string $name, string $value): self {
    // Ensure the variable name starts with --.
    if (!str_starts_with($name, '--')) {
      $name = '--' . $name;
    }
    $this->cssVariables[$name] = $value;
    return $this;
  }

  /**
   * Add a utility class.
   *
   * @param string $selector
   *   The utility class selector.
   * @param array $properties
   *   The CSS properties for the utility class.
   *
   * @return $this
   */
  public function addUtility(string $selector, array $properties): self {
    $this->addRule('@utility ' . ltrim($selector, '.'), $properties);
    return $this;
  }

  /**
   * Assert that a rule's properties are flat.
   *
   * The one definition of the flat declaration rule. `addRule()` calls it, so
   * every rule this class stores has passed it — info-file data and build
   * event subscriber data alike. The preparer calls it too, per extension, so
   * that it can name the extension at fault; that is why this is public and
   * static rather than an inline check.
   *
   * Refused:
   * - A property name carrying an uppercase letter, unless it begins `--`.
   *   Custom properties are case-sensitive, so their case is the caller's
   *   business.
   * - An array value, which is how a nested selector used to be written.
   *
   * @param string $selector
   *   The rule's selector, named in the message because it is one of the two
   *   things needed to find the declaration.
   * @param array $properties
   *   The rule's properties.
   *
   * @throws \InvalidArgumentException
   *   If a property name carries an uppercase letter, or a value is an array.
   */
  public static function assertFlatDeclarations(string $selector, array $properties): void {
    foreach ($properties as $property => $value) {
      $property = (string) $property;

      if (is_array($value)) {
        throw new \InvalidArgumentException(sprintf(
          'Tailwind rule "%s" gives the key "%s" an array value. Tailwind data in an info file is flat: move a rule that needs a nested selector, a state or a pseudo-element to an import entrypoint.',
          $selector,
          $property,
        ));
      }

      if (!str_starts_with($property, '--') && preg_match('/[A-Z]/', $property)) {
        throw new \InvalidArgumentException(sprintf(
          'Tailwind rule "%s" declares the property "%s" with an uppercase letter. Tailwind data in an info file is flat: write the property name in kebab-case, or move the rule to an import entrypoint.',
          $selector,
          $property,
        ));
      }
    }
  }

  /**
   * Add a CSS rule with selector and properties.
   *
   * @param string $selector
   *   CSS selector (e.g., '.class', '#id', 'div').
   * @param array $properties
   *   Associative array of flat CSS properties: kebab-case names, plus the
   *   `apply` key. See assertFlatDeclarations().
   * @param string|null $layer
   *   The components layer, or NULL for a top-level rule. The only layer this
   *   class knows; anything else is a caller error.
   *
   * @throws \InvalidArgumentException
   *   If the properties are not flat. This is the gate every rule passes.
   */
  public function addRule(string $selector, array $properties, ?string $layer = NULL): self {
    self::assertFlatDeclarations($selector, $properties);

    $rule = [
      'selector' => $selector,
      'properties' => $properties,
      'weight' => 0,
    ];

    if (isset($properties['apply'])) {
      $rule['weight'] = self::APPLY_WEIGHT;
    }

    $this->addRuleToStorage($rule, $layer);

    return $this;
  }

  /**
   * Add a rule to the appropriate storage location.
   *
   * @param array $rule
   *   The rule array with selector, properties and weight.
   * @param string|null $layer
   *   The components layer, or NULL for a top-level rule.
   *
   * @throws \InvalidArgumentException
   *   If a layer other than the components layer is named. Routing it to the
   *   top level instead would silently move the rule out of its layer.
   */
  private function addRuleToStorage(array $rule, ?string $layer = NULL): void {
    if ($layer === NULL) {
      $this->rules[] = $rule;
      return;
    }

    if ($layer !== self::LAYER) {
      throw new \InvalidArgumentException(sprintf(
        'Unknown layer "%s": this stylesheet emits only "%s".',
        $layer,
        self::LAYER,
      ));
    }

    $this->components[] = $rule;
  }

  /**
   * Sort callback for rules within a layer.
   *
   * Heavier rules last; equal weights by selector, ascending. APPLY_WEIGHT is
   * the only weight anything sets, so in practice this reads as "rules
   * carrying @apply after rules that do not, then alphabetical".
   *
   * @param array $a
   *   The first rule to compare.
   * @param array $b
   *   The second rule to compare.
   *
   * @return int
   *   The comparison result.
   */
  private function sort(array $a, array $b): int {
    if ($a['weight'] === $b['weight']) {
      return $a['selector'] <=> $b['selector'];
    }

    return $a['weight'] <=> $b['weight'];
  }

  /**
   * Add a variant for a component.
   *
   * @param string $name
   *   The name of the variant.
   * @param string|array $selectors
   *   The CSS selectors for the variant.
   */
  public function addVariant($name, string|array $selectors) {
    $this->variants[$name] = is_array($selectors) ? $selectors : [$selectors];
  }

  /**
   * Format a single CSS rule.
   *
   * One rule, one level. A rule's properties are flat, so there is nothing to
   * descend into; `$baseIndent` exists for the rule's own position, which is
   * one level in when it sits inside `@layer components`.
   *
   * @param array $rule
   *   The CSS rule to format.
   * @param string $baseIndent
   *   The base indentation string.
   *
   * @return string
   *   The formatted CSS string.
   */
  private function formatRule(array $rule, string $baseIndent = ''): string {
    $css = $baseIndent . $rule['selector'] . " {\n";

    foreach ($rule['properties'] as $property => $value) {
      $value = (string) $value;
      if ($property === 'apply') {
        $css .= $baseIndent . self::INDENT . $value;
      }
      else {
        $css .= $baseIndent . self::INDENT . $property . ': ' . $value;
      }

      // Add semicolon if not present.
      if (!str_ends_with(trim($value), ';')) {
        $css .= ';';
      }
      $css .= "\n";
    }

    $css .= $baseIndent . "}\n";

    return $css;
  }

  /**
   * Format theme variables as @theme block.
   *
   * The only place this class puts a custom property. A bare declaration
   * inside a `@layer` block is not valid CSS, so there is nowhere else for
   * one to go.
   *
   * @return string
   *   The formatted @theme block.
   */
  private function formatVariables(): string {
    if (empty($this->cssVariables)) {
      return '';
    }

    $css = "@theme {\n";

    foreach ($this->cssVariables as $name => $value) {
      $css .= self::INDENT . $name . ': ' . $value;

      // Add semicolon if not present.
      if (!str_ends_with(trim($value), ';')) {
        $css .= ';';
      }
      $css .= "\n";
    }

    $css .= "}\n";

    return $css;
  }

  /**
   * Format the rules of a layer, in comparator order.
   *
   * @param array $rules
   *   The layer's rules.
   * @param string $baseIndent
   *   The base indentation string.
   *
   * @return string
   *   The formatted CSS string for the layer.
   */
  private function formatLayerRules(array $rules, string $baseIndent = ''): string {
    $css = '';

    uasort($rules, fn($a, $b) => $this->sort($a, $b));
    foreach ($rules as $rule) {
      $css .= $this->formatRule($rule, $baseIndent);
    }

    return $css;
  }

  /**
   * Output all CSS rules as valid CSS string.
   *
   * @return string
   *   The formatted CSS string.
   */
  public function toCss(): string {
    $css = '';

    // Add source files.
    if (!empty($this->sources)) {
      $css .= "/* Sources */\n";
      foreach ($this->sources as $source) {
        $css .= $source . "\n";
      }

      // Add blank line after sources if there are other rules.
      if ($this->hasContentAfterSources()) {
        $css .= "\n";
      }
    }

    // Add theme variables.
    if (!empty($this->cssVariables)) {
      $css .= $this->formatVariables();

      // Add blank line after theme variables if there are other rules.
      if ($this->hasContentAfterTheme()) {
        $css .= "\n";
      }
    }

    // Add top-level rules, unsorted, in insertion order. Every @utility lands
    // here: Tailwind 4 will not register one inside a layer.
    foreach ($this->rules as $rule) {
      $css .= $this->formatRule($rule);
    }

    // Add the components layer.
    if (!empty($this->components)) {
      $css .= '@layer ' . self::LAYER . " {\n";
      $css .= $this->formatLayerRules($this->components, self::INDENT);
      $css .= "}\n\n";
    }

    if (!empty($this->variants)) {
      $css .= "/* Variants */\n";
      foreach ($this->variants as $name => $selectors) {
        $css .= '@custom-variant ' . $name . ' (' . implode(', ', $selectors) . ");\n";
      }
      $css .= "\n";
    }

    // Add @import statements LAST. This looks wrong and is not: see the
    // class docblock. An imported stylesheet is inlined after the @theme
    // block above it, which is what lets a theme's token beat a module's.
    if (!empty($this->imports)) {
      $css .= "/* Imports */\n";
      foreach ($this->imports as $import) {
        $css .= $import . "\n";
      }

      // Add blank line after imports if there are other rules.
      if ($this->hasContent()) {
        $css .= "\n";
      }
    }

    return trim($css);
  }

  /**
   * Check if there is any content to output.
   *
   * @return bool
   *   TRUE if there is content, FALSE otherwise.
   */
  private function hasContent(): bool {
    return !empty($this->sources) ||
           !empty($this->cssVariables) ||
           !empty($this->rules) ||
           $this->hasLayeredRules();
  }

  /**
   * Check if there is content after sources.
   *
   * @return bool
   *   TRUE if there is content after sources, FALSE otherwise.
   */
  private function hasContentAfterSources(): bool {
    return !empty($this->cssVariables) ||
           !empty($this->rules) ||
           $this->hasLayeredRules();
  }

  /**
   * Check if there is content after theme variables.
   *
   * @return bool
   *   TRUE if there is content after theme, FALSE otherwise.
   */
  private function hasContentAfterTheme(): bool {
    return !empty($this->rules) ||
           $this->hasLayeredRules();
  }

  /**
   * Check if there are any layered rules.
   *
   * @return bool
   *   TRUE if there are layered rules, FALSE otherwise.
   */
  private function hasLayeredRules(): bool {
    return !empty($this->components);
  }

}
