<?php

declare(strict_types=1);

namespace Drupal\neo_build_entity_print;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;
use Drupal\neo_build_entity_print\Asset\EntityPrintAssetRenderer;

/**
 * Swaps entity_print's asset renderer for the Neo-aware one.
 *
 * In DEV mode Neo serves stylesheets as JS modules from the Vite dev server,
 * which entity_print's own renderer never sees; the replacement renders those
 * alongside the CSS so printed entities pick up the dev server's styles. This
 * used to live in neo_build itself and made neo_build fail PHPStan on every
 * site without entity_print, with errors PHPStan refuses to ignore.
 */
class NeoBuildEntityPrintServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('entity_print.asset_renderer')) {
      $container->getDefinition('entity_print.asset_renderer')
        ->setClass(EntityPrintAssetRenderer::class);
    }
  }

}
