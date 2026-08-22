<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\Scope;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the closed scope set and everything each case has to answer.
 *
 * The enum is the one definition of the scope set, replacing a plugin type
 * whose YAML anybody could extend. Closing the set is the point, so the case
 * list is asserted exactly rather than "at least": a third case arriving by
 * accident should fail here, before it reaches a build loop.
 */
#[Group('neo_build')]
class ScopeTest extends UnitTestCase {

  /**
   * The set is exactly front and back.
   */
  public function testTheSetIsClosed(): void {
    $ids = array_map(static fn (Scope $scope): string => $scope->value, Scope::cases());
    $this->assertSame(['front', 'back'], $ids);
  }

  /**
   * A case's backing value is its scope id.
   */
  public function testTheBackingValueIsTheScopeId(): void {
    $this->assertSame('front', Scope::Front->value);
    $this->assertSame('back', Scope::Back->value);
    $this->assertSame(Scope::Front, Scope::from('front'));
    $this->assertSame(Scope::Back, Scope::from('back'));
    $this->assertNull(Scope::tryFrom('sideways'));
  }

  /**
   * The labels are the ones the scopes YAML carried.
   */
  public function testTheLabelsAreUnchanged(): void {
    $this->assertSame('Frontend Theme', Scope::Front->label());
    $this->assertSame('Backend Theme', Scope::Back->label());
  }

  /**
   * The descriptions are the ones the scopes YAML carried.
   */
  public function testTheDescriptionsAreUnchanged(): void {
    $this->assertSame('Focus on assets built for the frontend.', Scope::Front->description());
    $this->assertSame('Focus on assets built for the backend.', Scope::Back->description());
  }

  /**
   * A scope's id is the machine name of the theme it compiles into.
   */
  public function testScopeIdentity(): void {
    foreach (Scope::cases() as $scope) {
      $this->assertSame($scope->value, $scope->themeName(), sprintf(
        'Scope %s compiles into the theme of the same machine name.',
        $scope->value,
      ));
    }
  }

}
