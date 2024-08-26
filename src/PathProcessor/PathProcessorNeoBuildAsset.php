<?php

namespace Drupal\neo_build\PathProcessor;

use Drupal\Core\PathProcessor\InboundPathProcessorInterface;
use Symfony\Component\HttpFoundation\Request;

/**
 * Defines a path processor to rewrite image styles URLs.
 *
 * As the route system does not allow arbitrary amount of parameters convert
 * the file path to a query parameter on the request.
 *
 * This processor handles two different cases:
 * - public image styles: In order to allow the webserver to serve these files
 *   directly, the route is registered under the same path as the image style so
 *   it took over the first generation. Therefore the path processor converts
 *   the file path to a query parameter.
 * - private image styles: In contrast to public image styles, private
 *   derivatives are already using system/files/styles. Similar to public image
 *   styles, it also converts the file path to a query parameter.
 */
class PathProcessorNeoBuildAsset implements InboundPathProcessorInterface {

  /**
   * {@inheritdoc}
   */
  public function processInbound($path, Request $request) {
    $path_prefix = '/api/neo/asset';
    if (str_starts_with($path, $path_prefix . '/')) {
      // Strip out path prefix.
      $asset = preg_replace('|^' . preg_quote($path_prefix, '|') . '|', '', $path);
      $request->query->set('asset', $asset);
      return $path_prefix;
    }
    return $path;
  }

}
