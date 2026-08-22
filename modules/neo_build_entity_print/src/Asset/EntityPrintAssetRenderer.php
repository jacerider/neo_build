<?php

namespace Drupal\neo_build_entity_print\Asset;

use Drupal\Core\Asset\AttachedAssets;
use Drupal\entity_print\Asset\AssetRenderer;

/**
 * Render CSS assets for the entities being printed.
 */
class EntityPrintAssetRenderer extends AssetRenderer {

  /**
   * {@inheritdoc}
   */
  public function render(array $entities, $use_default_css = TRUE, $optimize_css = TRUE) {
    $render = parent::render($entities, $use_default_css, $optimize_css);

    /** @var \Drupal\neo_build\NeoBuild $neoBuild */
    $neoBuild = \Drupal::service('neo_build');
    if ($neoBuild->isDevMode()) {
      $build['#attached']['library'] = [];
      $build['#attached']['library'] = array_merge($this->assetCollector->getCssLibraries($entities), $build['#attached']['library']);

      [$jsAssetsHeader, $jsAssetsFooter] = $this->assetResolver->getJsAssets(AttachedAssets::createFromRenderArray($build), $optimize_css);
      $jsAssetsHeader = array_filter($jsAssetsHeader, function ($asset) {
        return isset($asset['options']['attributes']['neoCss']) && $asset['options']['attributes']['neoCss'] === TRUE;
      });
      $jsAssetsFooter = array_filter($jsAssetsFooter, function ($asset) {
        return !isset($asset['options']['attributes']['neoCss']) || $asset['options']['attributes']['neoCss'] !== TRUE;
      });
      /** @var \Drupal\Core\Asset\JsCollectionRenderer $jsRenderer */
      $jsRenderer = \Drupal::service('asset.js.collection_renderer');
      $render = array_merge($render, $jsRenderer->render($jsAssetsHeader));
      $footer = $jsRenderer->render($jsAssetsFooter);
      $render = array_merge($render, $footer);
    }
    return $render;
  }

}
