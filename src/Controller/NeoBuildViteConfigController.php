<?php

declare(strict_types = 1);

namespace Drupal\neo_build\Controller;

use Drupal\Core\Controller\ControllerBase;
use Symfony\Component\HttpFoundation\JsonResponse;

/**
 * Returns responses for Neo | Build routes.
 */
final class NeoBuildViteConfigController extends ControllerBase {

  /**
   * Builds the response.
   */
  public function __invoke() {
    $data = [];
    $file = DRUPAL_ROOT . '/../.neo/vite.config.json';
    if (file_exists($file)) {
      $data = json_decode(file_get_contents($file), TRUE);
    }
    return new JsonResponse($data);
  }

}
