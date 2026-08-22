<?php

declare(strict_types=1);

namespace Drupal\neo_build;

/**
 * One thing prepare has to say about what it did.
 *
 * The preparer does not print; the command does. Notices carry the messages
 * in the order they arose, typed so the command can pick the right marker.
 */
final class PrepareNotice {

  /**
   * A Neo extension was added to the collection.
   */
  public const EXTENSION_ADDED = 'extension_added';

  /**
   * A library's CSS or JS entrypoint does not exist and was skipped.
   */
  public const MISSING_ENTRYPOINT = 'missing_entrypoint';

  /**
   * The scope has no primary file, so no CSS artifact was written.
   */
  public const MISSING_PRIMARY_FILE = 'missing_primary_file';

  /**
   * Constructs a PrepareNotice.
   *
   * @param string $type
   *   One of the class constants.
   * @param string $message
   *   The human-readable message, without any marker.
   */
  public function __construct(
    private readonly string $type,
    private readonly string $message,
  ) {}

  /**
   * Gets the notice type.
   *
   * @return string
   *   One of the class constants.
   */
  public function getType(): string {
    return $this->type;
  }

  /**
   * Gets the message.
   *
   * @return string
   *   The message.
   */
  public function getMessage(): string {
    return $this->message;
  }

}
