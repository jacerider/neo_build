<?php

declare(strict_types=1);

namespace Drupal\neo_build;

/**
 * The build scopes, and the one definition of the set.
 *
 * A scope is a compile target: everything Neo builds is built for exactly one
 * of these, and the set is closed. It was a YAML plugin type, which promised
 * an extension point nothing across any site ever took up while costing a
 * manager, a service and an alter hook to keep answering. Adding a scope is
 * not a matter of shipping YAML in any case — it needs a theme, a base theme,
 * a primary source file, an inline library and a settings entry — so the set
 * is stated here and the promise withdrawn.
 *
 * Labels and descriptions are returned as plain strings and translated at the
 * point of use, which is where the YAML's translatable properties were
 * resolved too: the Drush commands pass them through `dt()`, and Drupal call
 * sites through `t()`.
 */
enum Scope: string {

  case Front = 'front';
  case Back = 'back';

  /**
   * The human-readable label for this scope.
   */
  public function label(): string {
    return match ($this) {
      self::Front => 'Frontend Theme',
      self::Back => 'Backend Theme',
    };
  }

  /**
   * A one-line description of what this scope covers.
   */
  public function description(): string {
    return match ($this) {
      self::Front => 'Focus on assets built for the frontend.',
      self::Back => 'Focus on assets built for the backend.',
    };
  }

  /**
   * The machine name of the theme this scope compiles into.
   *
   * Scope identity: a scope's id *is* the machine name of its theme, so this
   * returns the backing value. It exists as a method all the same, because the
   * rule is worth naming — without it every caller that needs the theme
   * rediscovers the mapping by its own means, and the collision reads as
   * coincidence rather than as the invariant it is.
   */
  public function themeName(): string {
    return $this->value;
  }

}
