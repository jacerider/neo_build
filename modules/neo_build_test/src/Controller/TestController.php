<?php

namespace Drupal\neo_build_test\Controller;

use Drupal\Core\Controller\ControllerBase;

/**
 * Render neo build test.
 */
class TestController extends ControllerBase {

  /**
   * Test.
   *
   * @return string
   *   Return Hello string.
   */
  public function test() {
    return ['#theme' => 'neo_build_test'];
  }

}
