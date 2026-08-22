<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

/**
 * Enhanced CSS builder.
 *
 * Supports layers, theme variables, nested rules and camelCase conversion.
 *
 * Basic usage with layers, theme variables, nested rules and camelCase
 * properties:
 *
 * $css = new TailwindStylesheet();
 *
 * // Add theme variables.
 * $css->addCssVariable('--bg-site', '100px')
 *     ->addCssVariable('--fg-size', '200px')
 *     ->addCssVariable('--primary-color', '#007bff');
 *
 * // Add rules with camelCase properties (will be converted to kebab-case).
 * $css->addRule('.btn', [
 *     'display' => 'inline-block',
 *     'padding' => '0.5rem 1rem',
 *     'border' => 'none',
 *     'borderRadius' => '4px', // Will become 'border-radius'
 *     // 'backgroundColor' will become 'background-color'.
 *     'backgroundColor' => 'var(--primary-color)',
 *     '&:hover' => [
 *       'backgroundColor' => 'var(--secondary-color)',
 *       'transform' => 'scale(1.05)',
 *     ],
 *     '&:active' => [
 *       'transform' => 'scale(0.95)',
 *     ],
 *     '& .icon' => [
 *       'marginRight' => '0.5rem', // Will become 'margin-right'
 *     ]
 *     ], 'components')
 * ->addRule('.text-center', [
 *     'textAlign' => 'center' // Will become 'text-align'
 *     ], 'utilities');
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
   * List of CSS rules organized by layers.
   *
   * @var array
   */
  private array $layers = [
    'components' => ['rules' => []],
    'utilities' => ['rules' => []],
  ];

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
   * Custom layers defined by the user.
   *
   * @var array
   */
  private array $customLayers = [];

  /**
   * Convert camelCase property names to kebab-case.
   *
   * @param string $property
   *   The property name that may be in camelCase.
   *
   * @return string
   *   The property name converted to kebab-case.
   */
  private function convertPropertyName(string $property): string {
    // If it's already kebab-case or contains special characters, return as-is.
    if (strpos($property, '-') !== FALSE ||
        strpos($property, ':') !== FALSE ||
        strpos($property, '&') !== FALSE ||
        str_starts_with($property, '--')) {
      return $property;
    }

    // Convert camelCase to kebab-case.
    return strtolower(preg_replace('/([a-z])([A-Z])/', '$1-$2', $property));
  }

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
   * @param string $layer
   *   The layer to add the variable to.
   */
  public function addCssVariable(string $name, string $value, string $layer = 'theme'): self {
    // Ensure the variable name starts with --.
    if (!str_starts_with($name, '--')) {
      $name = '--' . $name;
    }
    $this->cssVariables[$layer][$name] = $value;
    return $this;
  }

  /**
   * Add a custom layer definition.
   *
   * @param string $layer
   *   The layer name to add.
   */
  public function addLayer(string $layer): self {
    if (!isset($this->layers[$layer]) && !in_array($layer, $this->customLayers)) {
      $this->customLayers[] = $layer;
      $this->layers[$layer] = ['rules' => []];
    }

    return $this;
  }

  /**
   * Process properties to separate regular properties from nested selectors.
   *
   * @param array $properties
   *   The properties array that may contain nested selectors.
   *
   * @return array
   *   Array with 'properties' and 'nestedRules' keys.
   */
  private function processNestedProperties(array $properties): array {
    $regularProperties = [];
    $nestedRules = [];

    foreach ($properties as $key => $value) {
      if (is_array($value)) {
        // This is a nested selector - keep the original selector format.
        $nestedSelector = $this->normalizeNestedSelector($key);
        $nestedResult = $this->processNestedProperties($value);

        // Add the nested rule with its nested structure preserved.
        $nestedRules[] = [
          'selector' => $nestedSelector,
          'properties' => $nestedResult['properties'],
          'nestedRules' => $nestedResult['nestedRules'],
        ];
      }
      else {
        // This is a regular CSS property - convert camelCase to kebab-case.
        $convertedKey = $this->convertPropertyName($key);
        $regularProperties[$convertedKey] = $value;
      }
    }

    return [
      'properties' => $regularProperties,
      'nestedRules' => $nestedRules,
    ];
  }

  /**
   * Normalize nested selector format.
   *
   * @param string $nestedSelector
   *   The nested selector.
   *
   * @return string
   *   The normalized selector.
   */
  private function normalizeNestedSelector(string $nestedSelector): string {
    // If selector doesn't start with & and is a pseudo-class/element, add &.
    if (str_starts_with($nestedSelector, ':')) {
      return '&' . $nestedSelector;
    }

    // Otherwise return as-is.
    return $nestedSelector;
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
   * Add a CSS rule with selector and properties.
   *
   * @param string $selector
   *   CSS selector (e.g., '.class', '#id', 'div').
   * @param array $properties
   *   Associative array of CSS properties (camelCase will be converted to
   *   kebab-case).
   * @param string|null $layer
   *   Optional CSS layer ('base', 'components', 'utilities', or custom layer).
   */
  public function addRule(string $selector, array $properties, ?string $layer = NULL): self {
    // Process nested properties (this also handles camelCase conversion).
    $processed = $this->processNestedProperties($properties);

    // Create rule with nested structure preserved.
    $rule = [
      'selector' => $selector,
      'properties' => $processed['properties'],
      'nestedRules' => $processed['nestedRules'],
      'weight' => 0,
    ];

    if (isset($processed['properties']['apply'])) {
      $rule['weight'] = self::APPLY_WEIGHT;
    }

    $this->addRuleToStorage($rule, $layer);

    return $this;
  }

  /**
   * Add a rule to the appropriate storage location.
   *
   * @param array $rule
   *   The rule array with selector, properties, and possibly nestedRules.
   * @param string|null $layer
   *   Optional CSS layer.
   */
  private function addRuleToStorage(array $rule, ?string $layer = NULL): void {
    // Ensure nestedRules key exists for consistency.
    if (!isset($rule['nestedRules'])) {
      $rule['nestedRules'] = [];
    }

    if ($layer) {
      // Ensure the layer exists.
      if (!isset($this->layers[$layer])) {
        $this->addLayer($layer);
      }

      $this->layers[$layer]['rules'][] = $rule;
    }
    else {
      $this->rules[] = $rule;
    }
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
   * Format a single CSS rule with nested rules.
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

    // Add regular properties first.
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

    // Add nested rules if they exist.
    if (!empty($rule['nestedRules'])) {
      foreach ($rule['nestedRules'] as $nestedRule) {
        $css .= $this->formatRule($nestedRule, $baseIndent . self::INDENT);
      }
    }

    $css .= $baseIndent . "}\n";

    return $css;
  }

  /**
   * Format theme variables as @theme block.
   *
   * @return string
   *   The formatted @theme block.
   */
  private function formatVariables(string $layer = 'theme'): string {
    if (empty($this->cssVariables[$layer])) {
      return '';
    }

    $css = '';
    if ($layer === 'theme') {
      $css = "@theme {\n";
    }

    foreach ($this->cssVariables[$layer] as $name => $value) {
      $css .= self::INDENT . $name . ': ' . $value;

      // Add semicolon if not present.
      if (!str_ends_with(trim($value), ';')) {
        $css .= ';';
      }
      $css .= "\n";
    }

    if ($layer === 'theme') {
      $css .= "}\n";
    }

    return $css;
  }

  /**
   * Format rules within a layer.
   *
   * @param array $layerData
   *   The layer data containing the layer's rules.
   * @param string $baseIndent
   *   The base indentation string.
   *
   * @return string
   *   The formatted CSS string for the layer.
   */
  private function formatLayerRules(array $layerData, string $baseIndent = ''): string {
    $css = '';

    uasort($layerData['rules'], fn($a, $b) => $this->sort($a, $b));
    foreach ($layerData['rules'] as $rule) {
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
      $css .= $this->formatVariables('theme');

      // Add blank line after theme variables if there are other rules.
      if ($this->hasContentAfterTheme()) {
        $css .= "\n";
      }
    }

    // Add layer declarations if we have layered rules.
    if ($this->hasLayeredRules() && FALSE) {
      $layerNames = array_merge(['components', 'utilities'], $this->customLayers);
      $activeLayers = array_filter($layerNames, fn($layer) =>
        !empty($this->layers[$layer]['rules'])
      );

      if (!empty($activeLayers)) {
        $css .= '@layer ' . implode(', ', $activeLayers) . ";\n\n";
      }
    }

    // Add backward-compatible rules (without layers).
    foreach ($this->rules as $rule) {
      $css .= $this->formatRule($rule);
    }

    // Add layered rules in the correct order.
    $layerOrder = array_merge(['components', 'utilities'], $this->customLayers);

    foreach ($layerOrder as $layerName) {
      if (isset($this->layers[$layerName]) && !empty($this->layers[$layerName]['rules'])) {
        $css .= "@layer {$layerName} {\n";
        $css .= $this->formatVariables($layerName);
        $css .= $this->formatLayerRules($this->layers[$layerName], self::INDENT);
        $css .= "}\n\n";
      }
    }

    if (!empty($this->variants)) {
      $css .= "/* Variants */\n";
      foreach ($this->variants as $name => $selectors) {
        $css .= '@custom-variant ' . $name . ' (' . implode(', ', $selectors) . ");\n";
      }
      $css .= "\n";
    }

    // Add @import statements first (they must be at the beginning).
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
    foreach ($this->layers as $layerData) {
      if (!empty($layerData['rules'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

}
