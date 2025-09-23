<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use Drupal\Core\DependencyInjection\ContainerBuilder;
use Drupal\Core\DependencyInjection\ServiceProviderBase;

/**
 * Modifies the language manager service.
 */
class NeoBuildServiceProvider extends ServiceProviderBase {

  /**
   * {@inheritdoc}
   */
  public function alter(ContainerBuilder $container) {
    if ($container->hasDefinition('entity_print.asset_renderer')) {
      $definition = $container->getDefinition('entity_print.asset_renderer');
      $definition->setClass('\Drupal\neo_build\Asset\EntityPrintAssetRenderer');
    }
  }

}
