<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Event\NeoBuildInlineEvent;
use Drupal\neo_build\Scope;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins what the inline event calls the thing it is dispatched for.
 *
 * It called it a theme name, which worked only because a scope's id and its
 * theme's machine name are the same string — so following any subscriber meant
 * knowing about the collision first. It carries the scope itself now.
 *
 * The rename ships outright. A deprecated `getThemeName()` was declined, so
 * there is deliberately no state in which both accessors answer, and the
 * absence is asserted rather than assumed: a wrapper left behind by accident
 * would let an out-of-tree subscriber keep working and be discovered by
 * somebody else, on some other site, at some later release.
 */
#[Group('neo_build')]
class NeoBuildInlineEventTest extends UnitTestCase {

  /**
   * The event carries the scope case it was constructed with.
   */
  public function testTheEventCarriesTheScopeCase(): void {
    foreach (Scope::cases() as $scope) {
      $this->assertSame($scope, (new NeoBuildInlineEvent($scope))->getScope());
    }
  }

  /**
   * The old accessor is gone, with no wrapper standing in for it.
   */
  public function testTheOldAccessorIsGoneWithNoWrapper(): void {
    $this->assertFalse(
      method_exists(NeoBuildInlineEvent::class, 'getThemeName'),
      'getThemeName() was renamed to getScope() with no deprecation cycle.',
    );
    $this->assertFalse(
      property_exists(NeoBuildInlineEvent::class, 'themeName'),
      'The public $themeName property went with the accessor.',
    );
  }

}
