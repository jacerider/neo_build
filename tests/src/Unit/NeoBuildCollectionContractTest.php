<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\NeoBuildCollection;
use Drupal\Tests\UnitTestCase;
use PHPUnit\Framework\Attributes\Group;

/**
 * Pins the collection methods the sibling packages' subscribers call.
 *
 * The neo_icon, neo_font, neo_modal, neo_alchemist and neo_color packages
 * subscribe to the build event and call these seven methods on the collection.
 * Their names and signatures are the collection's public edge: a rename
 * here is a release in five other packages. This test fails loudly on one.
 *
 * One signature has been removed since this list was published, deliberately
 * and as an announced exception: `addTailwindBase(array $data)`. The base
 * layer it fed reached no stylesheet on any site, because neo_font — its only
 * caller anywhere — had always passed an empty array. It was removed end to
 * end together with neo_font's call, so no sibling subscriber was left calling
 * a method that had gone. Sibling authors were told rather than left to
 * discover it: breaking a published signature silently is the failure this
 * test exists to prevent, and an announced break is not an exemption from it.
 */
#[Group('neo_build')]
class NeoBuildCollectionContractTest extends UnitTestCase {

  /**
   * The subscriber-facing methods and their parameter lists.
   */
  protected const CONTRACT = [
    'addTailwindTheme' => 'array $data',
    'addTailwindThemeItem' => 'string $key, string $value, ?string $position = NULL',
    'addTailwindComponents' => 'array $data',
    'addTailwindUtility' => 'string $key, array $properties',
    'addTailwindUtilities' => 'array $data',
    'addTailwindVariants' => 'array $data',
    'addTailwindSource' => 'string $id, string $path',
  ];

  /**
   * Each method exists, is public, has the pinned parameters, returns self.
   */
  public function testSubscriberFacingMethodsKeepTheirNamesAndSignatures(): void {
    foreach (self::CONTRACT as $name => $signature) {
      $this->assertTrue(method_exists(NeoBuildCollection::class, $name), $name);
      $method = new \ReflectionMethod(NeoBuildCollection::class, $name);
      $this->assertTrue($method->isPublic(), $name);
      $this->assertSame($signature, $this->signature($method), $name);
      $this->assertSame('self', (string) $method->getReturnType(), $name);
    }
  }

  /**
   * Renders a method's parameter list the way it reads in the source.
   */
  protected function signature(\ReflectionMethod $method): string {
    $parts = [];
    foreach ($method->getParameters() as $parameter) {
      $part = $parameter->getType() . ' $' . $parameter->getName();
      if ($parameter->isDefaultValueAvailable()) {
        $part .= ' = ' . var_export($parameter->getDefaultValue(), TRUE);
      }
      $parts[] = $part;
    }
    return implode(', ', $parts);
  }

}
