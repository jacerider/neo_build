<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\NeoBuild;
use Drupal\neo_build\NeoExtensionList;
use Drupal\neo_build\NeoLibrary;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins that NeoBuild rewrites libraries and does nothing else.
 *
 * The manifest chain moved to ManifestResolver. These assertions are what stop
 * it drifting back: a NeoManifest property or a `new NeoManifest` inside
 * NeoBuild would mean two owners for one question again, and the per-call
 * construction it used to do ran before the guard that returns early — a
 * file_exists, a read and a json_decode per extension, for a manifest the
 * service had explicitly forbidden itself to use.
 */
#[Group('neo_build')]
class NeoBuildRewriteContractTest extends UnitTestCase {

  /**
   * Criterion: NeoBuild holds no manifest property.
   */
  public function testNeoBuildHoldsNoManifestProperty(): void {
    // A reference to the *resolver* is the point of this ticket, so the
    // assertion is about holding a NeoManifest, not about the word.
    $manifestProperties = [];
    foreach ((new \ReflectionClass(NeoBuild::class))->getProperties() as $property) {
      $type = $property->getType();
      $name = $type instanceof \ReflectionNamedType ? $type->getName() : '';
      if (str_contains($name, 'NeoManifest') || $property->getName() === 'manifest') {
        $manifestProperties[] = $property->getName();
      }
    }

    $this->assertSame([], $manifestProperties, 'The manifest belongs to the resolver now.');
  }

  /**
   * Criterion: NeoBuild constructs no NeoManifest.
   */
  public function testNeoBuildConstructsNoManifest(): void {
    $source = file_get_contents((new \ReflectionClass(NeoBuild::class))->getFileName());

    $this->assertStringNotContainsString(
      'new NeoManifest',
      $source,
      'Building a manifest here is what made the rewrite read the wrong scope.',
    );
  }

  /**
   * Criterion: NeoBuild injects the resolver.
   */
  public function testNeoBuildInjectsTheManifestResolver(): void {
    $constructor = (new \ReflectionClass(NeoBuild::class))->getConstructor();
    $this->assertNotNull($constructor);

    $types = [];
    foreach ($constructor->getParameters() as $parameter) {
      $type = $parameter->getType();
      $types[] = $type instanceof \ReflectionNamedType ? $type->getName() : '';
    }

    $this->assertContains('Drupal\neo_build\ManifestResolver', $types);
  }

  /**
   * Criterion: getLibrary() declares ?NeoLibrary.
   */
  public function testGetLibraryDeclaresNullableNeoLibrary(): void {
    $method = new \ReflectionMethod(NeoExtensionList::class, 'getLibrary');
    $returnType = $method->getReturnType();

    $this->assertInstanceOf(\ReflectionNamedType::class, $returnType, 'getLibrary() declares no return type.');
    $this->assertSame(NeoLibrary::class, $returnType->getName());
    $this->assertTrue($returnType->allowsNull(), 'Two of its three paths return no library.');
  }

  /**
   * Criterion: getLibrary() returns NULL explicitly on both non-library paths.
   *
   * A method that promises an object and falls off the end instead is the
   * TypeError this plan was written from. Implicit NULL is what hid it.
   */
  public function testGetLibraryReturnsNullExplicitly(): void {
    $method = new \ReflectionMethod(NeoExtensionList::class, 'getLibrary');
    $source = file(($method->getFileName()));
    $body = implode('', array_slice(
      $source,
      $method->getStartLine() - 1,
      $method->getEndLine() - $method->getStartLine() + 1,
    ));

    $this->assertSame(
      2,
      substr_count($body, 'return NULL;'),
      'Both non-library paths must say NULL rather than falling off the end.',
    );
  }

}
