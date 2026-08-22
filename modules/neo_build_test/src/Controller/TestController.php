<?php

namespace Drupal\neo_build_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Render neo build test.
 */
class TestController extends ControllerBase {

  /**
   * Renders the manual browser check for the fixture's front library.
   *
   * @return array
   *   A render array.
   */
  public function test() {
    return ['#theme' => 'neo_build_test'];
  }

}
