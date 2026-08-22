<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Kernel;

use Drupal\KernelTests\KernelTestBase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Tests that the Neo extension list answers every call, not just the first.
 *
 * The list is built once by all() and cached. The un-scoped branch used to
 * return a local that only the building pass assigned, so a second un-scoped
 * call in one process — or an un-scoped call after a scoped one — returned
 * NULL where an array was promised and threw a TypeError. Every caller today
 * happens to call once per process, which is the only reason it went
 * unnoticed: the status-report page plus any other un-scoped caller in one
 * request would 500.
 */
#[Group('neo_build')]
class NeoExtensionListTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'neo_build',
    // A Neo extension (`neo: true`), so the list is not empty.
    'neo_build_test',
  ];

  /**
   * A second un-scoped call returns the same built list.
   */
  public function testReturnsTheSameBuiltListOnTheSecondUnscopedCall(): void {
    $list = $this->container->get('extension.list.neo');

    $first = $list->all();
    $second = $list->all();

    $this->assertArrayHasKey('neo_build_test', $first);
    $this->assertSame(array_keys($first), array_keys($second));
    $this->assertSame($first['neo_build_test'], $second['neo_build_test']);
  }

  /**
   * An un-scoped call after a scoped one returns the built list.
   *
   * The scoped branch built and cached the list; the un-scoped branch then
   * has nothing left to build and must answer from the cache.
   */
  public function testReturnsTheBuiltListWhenTheScopedCallCameFirst(): void {
    $list = $this->container->get('extension.list.neo');

    $front = $list->all(['front']);
    $all = $list->all();

    $this->assertArrayHasKey('neo_build_test', $front);
    $this->assertArrayHasKey('neo_build_test', $all);
    $this->assertSame(array_keys($front), array_keys(array_filter($all, fn ($extension) => $extension->allowScope(['front']))));
  }

  /**
   * A scoped call after an un-scoped one filters the built list.
   *
   * The neo_build_test module declares no scope, so it is in both scopes and
   * must survive either filter; the filtered list is exactly the built list's
   * members that allow the scope.
   */
  public function testFiltersByScopeAfterAnUnscopedCallHasBuiltTheList(): void {
    $list = $this->container->get('extension.list.neo');

    $all = $list->all();
    $front = $list->all(['front']);
    $back = $list->all(['back']);

    $this->assertArrayHasKey('neo_build_test', $front);
    $this->assertArrayHasKey('neo_build_test', $back);
    $this->assertSame(array_keys(array_filter($all, fn ($extension) => $extension->allowScope(['front']))), array_keys($front));
    $this->assertSame(array_keys(array_filter($all, fn ($extension) => $extension->allowScope(['back']))), array_keys($back));
  }

}
