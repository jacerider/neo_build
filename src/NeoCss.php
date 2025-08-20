<?php

declare(strict_types=1);

namespace Drupal\neo_build;

/**
 * Enhanced CSS builder with layer support, theme variables, nested rules, and camelCase conversion.
 *
 * Basic usage with layers, theme variables, nested rules, and camelCase properties:
 *
 * $css = new NeoCss();
 *
 * // Add theme variables.
 * $css->addCssVariable('--bg-site', '100px')
 *     ->addCssVariable('--fg-size', '200px')
 *     ->addCssVariables([
 *         '--primary-color' => '#007bff',
 *         '--secondary-color' => '#6c757d'
 *     ]);
 *
 * // Add rules with camelCase properties (will be converted to kebab-case).
 * $css->addRule('.btn', [
 *     'display' => 'inline-block',
 *     'padding' => '0.5rem 1rem',
 *     'border' => 'none',
 *     'borderRadius' => '4px', // Will become 'border-radius'
 *     'backgroundColor' => 'var(--primary-color)', // Will become 'background-color'
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
 *     ], null, 'components')
 * ->addRule('.text-center', [
 *     'textAlign' => 'center' // Will become 'text-align'
 *     ], null, 'utilities');
 *
 * echo $css->toCss();
 */
class NeoCss {

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
    'base' => ['rules' => [], 'mediaQueries' => []],
    'components' => ['rules' => [], 'mediaQueries' => []],
    'utilities' => ['rules' => [], 'mediaQueries' => []],
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
   * List of media queries without layers (for backward compatibility).
   *
   * @var array
   */
  private array $mediaQueries = [];

  /**
   * Custom layers defined by the user.
   *
   * @var array
   */
  private array $customLayers = [];

  /**
   * Indentation spaces.
   */
  private string $indent = '  ';

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
   * Convert camelCase properties in an array to kebab-case.
   *
   * @param array $properties
   *   Array of properties that may contain camelCase keys.
   *
   * @return array
   *   Array with kebab-case property names.
   */
  private function convertProperties(array $properties): array {
    $converted = [];

    foreach ($properties as $key => $value) {
      if (is_array($value)) {
        // This is a nested selector - recursively convert its properties.
        $converted[$key] = $this->convertProperties($value);
      }
      else {
        // Convert the property name to kebab-case.
        $convertedKey = $this->convertPropertyName($key);
        $converted[$convertedKey] = $value;
      }
    }

    return $converted;
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
   * Remove an @import statement.
   *
   * @param string $url
   *   The URL to remove.
   */
  public function removeImport(string $url): self {
    // Handle different URL formats for matching.
    $patterns = [
      '@import "' . $url . '";',
      '@import \'' . $url . '\';',
      '@import url(' . $url . ');',
      '@import url("' . $url . '");',
      '@import url(\'' . $url . '\');',
    ];

    $this->imports = array_filter($this->imports, function ($import) use ($patterns, $url) {
      foreach ($patterns as $pattern) {
        if (str_starts_with($import, $pattern)) {
          return FALSE;
        }
      }
        return !str_contains($import, $url);
    });

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
   * Remove a source file.
   *
   * @param string $url
   *   The URL to remove.
   */
  public function removeSource(string $url): self {
    // Handle different URL formats for matching.
    $patterns = [
      '@source "' . $url . '";',
      '@source \'' . $url . '\';',
      '@source url(' . $url . ');',
      '@source url("' . $url . '");',
      '@source url(\'' . $url . '\');',
    ];

    $this->sources = array_filter($this->sources, function ($source) use ($patterns, $url) {
      foreach ($patterns as $pattern) {
        if (str_starts_with($source, $pattern)) {
          return FALSE;
        }
      }
      return !str_contains($source, $url);
    });

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
   * Add multiple CSS custom properties to the theme.
   *
   * @param array $variables
   *   Associative array of variable names and values.
   * @param string $layer
   *   The layer to add the variables to.
   */
  public function addCssVariables(array $variables, string $layer = 'theme'): self {
    foreach ($variables as $name => $value) {
      $this->addCssVariable($name, $value, $layer);
    }
    return $this;
  }

  /**
   * Remove a theme variable.
   *
   * @param string $name
   *   The variable name to remove.
   * @param string $layer
   *   The layer to remove the variable from.
   */
  public function removeCssVariable(string $name, string $layer = 'theme'): self {
    // Ensure the variable name starts with --.
    if (!str_starts_with($name, '--')) {
      $name = '--' . $name;
    }

    unset($this->cssVariables[$layer][$name]);

    return $this;
  }

  /**
   * Get all theme variables.
   *
   * @return array
   *   Array of theme variables.
   */
  public function getCssVariables(string $layer = 'theme'): array {
    return $this->cssVariables[$layer] ?? [];
  }

  /**
   * Clear all theme variables.
   */
  public function clearCssVariables(): self {
    $this->cssVariables = [];
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
      $this->layers[$layer] = ['rules' => [], 'mediaQueries' => []];
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
   * @param string|null $media
   *   Optional media query.
   *
   * @return $this
   */
  public function addUtility(string $selector, array $properties, ?string $media = NULL): self {
    $this->addRule('@utility ' . ltrim($selector, '.'), $properties, $media);
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
   * @param string|null $media
   *   Optional media query (e.g., 'screen and (max-width: 768px)').
   * @param string|null $layer
   *   Optional CSS layer ('base', 'components', 'utilities', or custom layer).
   */
  public function addRule(string $selector, array $properties, ?string $media = NULL, ?string $layer = NULL): self {
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
      $rule['weight'] = 1000;
    }

    $this->addRuleToStorage($rule, $media, $layer);

    return $this;
  }

  /**
   * Add a rule to the appropriate storage location.
   *
   * @param array $rule
   *   The rule array with selector, properties, and possibly nestedRules.
   * @param string|null $media
   *   Optional media query.
   * @param string|null $layer
   *   Optional CSS layer.
   */
  private function addRuleToStorage(array $rule, ?string $media = NULL, ?string $layer = NULL): void {
    // Ensure nestedRules key exists for consistency.
    if (!isset($rule['nestedRules'])) {
      $rule['nestedRules'] = [];
    }

    if ($layer) {
      // Ensure the layer exists.
      if (!isset($this->layers[$layer])) {
        $this->addLayer($layer);
      }

      if ($media) {
        if (!isset($this->layers[$layer]['mediaQueries'][$media])) {
          $this->layers[$layer]['mediaQueries'][$media] = [];
        }
        $this->layers[$layer]['mediaQueries'][$media][] = $rule;
      }
      else {
        $this->layers[$layer]['rules'][] = $rule;
      }
    }
    else {
      // Backward compatibility - no layer specified.
      if ($media) {
        if (!isset($this->mediaQueries[$media])) {
          $this->mediaQueries[$media] = [];
        }
        $this->mediaQueries[$media][] = $rule;
      }
      else {
        $this->rules[] = $rule;
      }
    }
  }

  /**
   * Sort callback for rules.
   *
   * @param array $a
   *   The first rule to compare.
   * @param array $b
   *   The second rule to compare.
   *
   * @return int
   *   The comparison result.
   */
  private function sort($a, $b): int {
    // Check if either rule has an 'apply' property.
    $aHasApply = isset($a['properties']['apply']);
    $bHasApply = isset($b['properties']['apply']);

    // If only one has apply, check for dependency first.
    if ($aHasApply && !$bHasApply) {
      // Rule A has apply - check if it references rule B's selector.
      $applyValue = $a['properties']['apply'];
      if ($this->applyReferencesSelector($applyValue, $b['selector'])) {
        // A should come after B (A depends on B)
        return 1;
      }
      // If no dependency, rules with apply still come after rules without apply.
      return 1;
    }

    if ($bHasApply && !$aHasApply) {
      // Rule B has apply - check if it references rule A's selector.
      $applyValue = $b['properties']['apply'];
      if ($this->applyReferencesSelector($applyValue, $a['selector'])) {
        // B should come after A (B depends on A)
        return -1;
      }
      // If no dependency, rules with apply still come after rules without apply.
      return -1;
    }

    // If both have apply, check for cross-dependencies.
    if ($aHasApply && $bHasApply) {
      $aApplyValue = $a['properties']['apply'];
      $bApplyValue = $b['properties']['apply'];

      $aReferencesB = $this->applyReferencesSelector($aApplyValue, $b['selector']);
      $bReferencesA = $this->applyReferencesSelector($bApplyValue, $a['selector']);

      if ($aReferencesB && !$bReferencesA) {
        // A depends on B, so B comes first.
        return 1;
      }
      if ($bReferencesA && !$aReferencesB) {
        // B depends on A, so A comes first.
        return -1;
      }

      // If no dependencies or circular dependencies, fall through to weight/selector sorting.
    }

    // If both have apply or neither have apply, use existing weight/selector logic.
    if ($a['weight'] === $b['weight']) {
      // Sort by selector name.
      return $a['selector'] <=> $b['selector'];
    }
    return $a['weight'] <=> $b['weight'];
  }

  /**
   * Check if an apply rule references a specific selector.
   *
   * @param string $applyValue
   *   The apply rule value
   *   (e.g., '@apply btn;' or '@apply block btn bg-default;').
   * @param string $selector
   *   The selector to check for (e.g., '.btn').
   *
   * @return bool
   *   TRUE if the apply rule references the selector, FALSE otherwise.
   */
  private function applyReferencesSelector(string $applyValue, string $selector): bool {
    // Extract class names from the apply value
    // Remove '@apply' and ';', normalize whitespace, and split by spaces.
    $applyValue = preg_replace('/^@apply\s+/', '', trim($applyValue));
    $applyValue = preg_replace('/;\s*$/', '', $applyValue);
    $applyValue = preg_replace('/\s+/', ' ', trim($applyValue));

    if (empty($applyValue)) {
      return FALSE;
    }

    $appliedClasses = explode(' ', $applyValue);

    // Convert selector to class name for comparison
    // Handle various selector formats: '.btn', '#id', 'element', etc.
    $className = ltrim($selector, '.#');

    // Check if any of the applied classes match the selector.
    return in_array($className, $appliedClasses);
  }

  /**
   * Add multiple rules at once.
   *
   * @param array $rules
   *   Array of rules, each containing 'selector', 'properties', and
   *   optionally 'media' and 'layer'.
   */
  public function addRules(array $rules): self {
    foreach ($rules as $rule) {
      $this->addRule(
        $rule['selector'],
        $rule['properties'],
        $rule['media'] ?? NULL,
        $rule['layer'] ?? NULL
      );
    }

    return $this;
  }

  /**
   * Add a single property to an existing selector or create new rule.
   *
   * @param string $selector
   *   The CSS selector to modify.
   * @param string $property
   *   The CSS property to add or modify (camelCase will be converted).
   * @param string $value
   *   The value of the CSS property.
   * @param string|null $media
   *   Optional media query for the property.
   * @param string|null $layer
   *   Optional CSS layer for the property.
   */
  public function addProperty(string $selector, string $property, string $value, ?string $media = NULL, ?string $layer = NULL): self {
    // Convert camelCase property to kebab-case.
    $property = $this->convertPropertyName($property);

    $found = FALSE;

    if ($layer) {
      // Ensure the layer exists.
      if (!isset($this->layers[$layer])) {
        $this->addLayer($layer);
      }

      $targetArray = $media ? ($this->layers[$layer]['mediaQueries'][$media] ?? []) : $this->layers[$layer]['rules'];

      foreach ($targetArray as &$rule) {
        if ($rule['selector'] === $selector) {
          $rule['properties'][$property] = $value;
          $found = TRUE;
          break;
        }
      }

      if (!$found) {
        $this->addRule($selector, [$property => $value], $media, $layer);
      }
      else {
        if ($media) {
          $this->layers[$layer]['mediaQueries'][$media] = $targetArray;
        }
        else {
          $this->layers[$layer]['rules'] = $targetArray;
        }
      }
    }
    else {
      // Backward compatibility - no layer specified.
      $targetArray = $media ? ($this->mediaQueries[$media] ?? []) : $this->rules;

      foreach ($targetArray as &$rule) {
        if ($rule['selector'] === $selector) {
          $rule['properties'][$property] = $value;
          $found = TRUE;
          break;
        }
      }

      if (!$found) {
        $this->addRule($selector, [$property => $value], $media);
      }
      else {
        if ($media) {
          $this->mediaQueries[$media] = $targetArray;
        }
        else {
          $this->rules = $targetArray;
        }
      }
    }

    return $this;
  }

  /**
   * Remove a rule by selector.
   *
   * @param string $selector
   *   The CSS selector to remove.
   * @param string|null $media
   *   Optional media query for the rule.
   * @param string|null $layer
   *   Optional CSS layer for the rule.
   */
  public function removeRule(string $selector, ?string $media = NULL, ?string $layer = NULL): self {
    if ($layer && isset($this->layers[$layer])) {
      if ($media) {
        if (isset($this->layers[$layer]['mediaQueries'][$media])) {
          $this->layers[$layer]['mediaQueries'][$media] = array_filter(
                $this->layers[$layer]['mediaQueries'][$media],
                fn($rule) => $rule['selector'] !== $selector
            );

          // Remove media query if empty.
          if (empty($this->layers[$layer]['mediaQueries'][$media])) {
            unset($this->layers[$layer]['mediaQueries'][$media]);
          }
        }
      }
      else {
        $this->layers[$layer]['rules'] = array_filter(
              $this->layers[$layer]['rules'],
              fn($rule) => $rule['selector'] !== $selector
          );
      }
    }
    else {
      // Backward compatibility - remove from non-layered rules.
      if ($media) {
        if (isset($this->mediaQueries[$media])) {
          $this->mediaQueries[$media] = array_filter(
                $this->mediaQueries[$media],
                fn($rule) => $rule['selector'] !== $selector
            );

          // Remove media query if empty.
          if (empty($this->mediaQueries[$media])) {
            unset($this->mediaQueries[$media]);
          }
        }
      }
      else {
        $this->rules = array_filter($this->rules, fn($rule) => $rule['selector'] !== $selector);
      }
    }

    return $this;
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
   * Clear all rules and imports.
   */
  public function clear(): self {
    $this->imports = [];
    $this->sources = [];
    $this->rules = [];
    $this->mediaQueries = [];
    $this->cssVariables = [];
    $this->layers = [
      'base' => ['rules' => [], 'mediaQueries' => []],
      'components' => ['rules' => [], 'mediaQueries' => []],
      'utilities' => ['rules' => [], 'mediaQueries' => []],
    ];
    $this->customLayers = [];
    return $this;
  }

  /**
   * Set indentation string.
   *
   * @param string $indent
   *   The indentation string to use.
   */
  public function setIndent(string $indent): self {
    $this->indent = $indent;
    return $this;
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
        $css .= $baseIndent . $this->indent . $value;
      }
      else {
        $css .= $baseIndent . $this->indent . $property . ': ' . $value;
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
        $css .= $this->formatRule($nestedRule, $baseIndent . $this->indent);
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
      $css .= $this->indent . $name . ': ' . $value;

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
   *   The layer data containing rules and media queries.
   * @param string $baseIndent
   *   The base indentation string.
   *
   * @return string
   *   The formatted CSS string for the layer.
   */
  private function formatLayerRules(array $layerData, string $baseIndent = ''): string {
    $css = '';

    uasort($layerData['rules'], fn($a, $b) => $this->sort($a, $b));
    // Add regular rules.
    foreach ($layerData['rules'] as $rule) {
      $css .= $this->formatRule($rule, $baseIndent);
    }

    // Add media queries.
    foreach ($layerData['mediaQueries'] as $media => $rules) {
      $css .= $baseIndent . "@media {$media} {\n";

      foreach ($rules as $rule) {
        $css .= $this->formatRule($rule, $baseIndent . $this->indent);
      }

      $css .= $baseIndent . "}\n";
    }

    return $css;
  }

  /**
   * Output all CSS rules as valid CSS string.
   *
   * @param bool $minify
   *   Whether to minify the output.
   *
   * @return string
   *   The formatted CSS string.
   */
  public function toCss(bool $minify = FALSE): string {
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
      $layerNames = array_merge(['base', 'components', 'utilities'], $this->customLayers);
      $activeLayers = array_filter($layerNames, fn($layer) =>
        !empty($this->layers[$layer]['rules']) || !empty($this->layers[$layer]['mediaQueries'])
      );

      if (!empty($activeLayers)) {
        $css .= '@layer ' . implode(', ', $activeLayers) . ";\n\n";
      }
    }

    // Add backward-compatible rules (without layers).
    foreach ($this->rules as $rule) {
      $css .= $this->formatRule($rule);
    }

    // Add backward-compatible media queries (without layers).
    foreach ($this->mediaQueries as $media => $rules) {
      $css .= "@media {$media} {\n";

      foreach ($rules as $rule) {
        $css .= $this->formatRule($rule, $this->indent);
      }

      $css .= "}\n";
    }

    // Add layered rules in the correct order.
    $layerOrder = array_merge(['base', 'components', 'utilities'], $this->customLayers);

    foreach ($layerOrder as $layerName) {
      if (isset($this->layers[$layerName]) &&
          (!empty($this->layers[$layerName]['rules']) || !empty($this->layers[$layerName]['mediaQueries']))) {
        $css .= "@layer {$layerName} {\n";
        $css .= $this->formatVariables($layerName);
        $css .= $this->formatLayerRules($this->layers[$layerName], $this->indent);
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

    if ($minify) {
      return $this->minify($css);
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
           !empty($this->mediaQueries) ||
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
           !empty($this->mediaQueries) ||
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
           !empty($this->mediaQueries) ||
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
      if (!empty($layerData['rules']) || !empty($layerData['mediaQueries'])) {
        return TRUE;
      }
    }
    return FALSE;
  }

  /**
   * Minify CSS output.
   *
   * @param string $css
   *   The CSS string to minify.
   *
   * @return string
   *   The minified CSS string.
   */
  private function minify(string $css): string {
    // Remove comments.
    $css = preg_replace('/\/\*.*?\*\//s', '', $css);

    // Remove unnecessary whitespace.
    $css = preg_replace('/\s+/', ' ', $css);

    // Remove spaces around certain characters.
    $css = preg_replace('/\s*([{}:;,>+~])\s*/', '$1', $css);

    // Remove trailing semicolons before closing braces.
    $css = str_replace(';}', '}', $css);

    return trim($css);
  }

  /**
   * Get all imports, rules and media queries as array.
   *
   * @return array
   *   An associative array of all CSS rules.
   */
  public function getRules(): array {
    return [
      'imports' => $this->imports,
      'sources' => $this->sources,
      'cssVariables' => $this->cssVariables,
      'rules' => $this->rules,
      'mediaQueries' => $this->mediaQueries,
      'layers' => $this->layers,
    ];
  }

  /**
   * Magic method to output CSS when object is converted to string.
   *
   * @return string
   *   The formatted CSS string.
   */
  public function __toString(): string {
    return $this->toCss();
  }

}
