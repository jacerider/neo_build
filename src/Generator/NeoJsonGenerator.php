<?php

declare(strict_types=1);

namespace Drupal\neo_build\Generator;

use Drupal\neo_build\NeoBuildCollection;

/**
 * Generates neo.json, what the Node side of the build reads.
 *
 * The Vite config and the Neo Tailwind plugin read this file. It carries the
 * port and the dev flag, the roots and scope, the ignored globs, the
 * Vite entrypoints, the stylelint globs, the icon data — and, under tailwind,
 * only what the partition rule leaves to it: the theme without its CSS
 * variables, and the icon libraries and icons. The CSS-owned keys — source,
 * import, base, components, utilities, variants — are emitted empty, because
 * the plugin reads exactly those keys and the stylesheet owns their content.
 *
 * The partition rule replaces an ordering accident: the CSS build used to
 * drain the collection of everything the stylesheet owns before this file was
 * serialised. The output is the same; the collection is no longer touched.
 */
final class NeoJsonGenerator implements ArtifactGeneratorInterface {

  /**
   * The artifact's file name, relative to the project root.
   */
  public const FILENAME = 'neo.json';

  /**
   * {@inheritdoc}
   */
  public function generate(NeoBuildCollection $collection): ?Artifact {
    // A theme key beginning `--` whose value is a string is a CSS variable and
    // belongs to the stylesheet; anything else in the theme stays here for the
    // plugin, as it always has.
    $theme = array_filter(
      $collection->getTailwindTheme(),
      fn ($value, string $key): bool => !(str_starts_with($key, '--') && is_string($value)),
      ARRAY_FILTER_USE_BOTH,
    );

    $data = [
      'port' => $collection->getPort(),
      'dev' => $collection->isDev(),
      'root' => $collection->getRoot(),
      'docRoot' => $collection->getDocRoot(),
      'neoRoot' => $collection->getNeoRoot(),
      'primaryRoot' => $collection->getPrimaryRoot(),
      'primaryFile' => $collection->getPrimaryFile(),
      'scope' => $collection->getScope(),
      'ignored' => $collection->getIgnored(),
      'tailwind' => [
        'source' => [],
        'import' => [],
        'base' => [],
        'theme' => $theme,
        'utilities' => [],
        'components' => [],
        'variants' => [],
        'icon_libraries' => array_values($collection->getTailwindIconLibraries()),
        'icons' => $collection->getTailwindIcons(),
      ],
      'vite' => [
        'lib' => array_values($collection->getViteLibs()),
      ],
      'stylelint' => array_values($collection->getStylelint()),
    ];

    return new Artifact(
      $collection->getRoot() . self::FILENAME,
      (string) json_encode($data, JSON_PRETTY_PRINT),
    );
  }

}
