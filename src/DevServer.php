<?php

declare(strict_types=1);

namespace Drupal\neo_build;

use GuzzleHttp\ClientInterface;

/**
 * The one answer to "is the Vite dev server up, and where?".
 *
 * There used to be three, and they disagreed. Drush opened a raw TCP socket to
 * the configured port, so any process answering there counted. The build CLI
 * issued an HTTP GET for `/@vite/client`, so only a real Vite server counted.
 * And the URL the rewritten assets pointed at had `:5173` hard-coded and
 * ignored the configured port entirely — so on a site that moved the port, both
 * probes agreed the server was up while every asset URL pointed somewhere else.
 *
 * This is a service rather than a value object, which reverses the spec's first
 * decision: a value doing a real socket connect leaves the status branches
 * untestable, and making them testable is the whole reason it became a service.
 *
 * `neo_build` requires DDEV by documented design, and this class introduces no
 * fallback and no second environment. What it adds is legibility: when the DDEV
 * variable is absent it has no URL to give and says so, rather than composing
 * `":5173/"` out of an unset variable.
 */
final class DevServer {

  /**
   * The DDEV variable the dev server URL is built from.
   *
   * Read through getenv() rather than $_ENV, matching getenv('DDEV_PROJECT')
   * in the Drush command class: independent of variables_order, and readable
   * in a unit test, where $_ENV under PHPUnit is not.
   */
  public const ENVIRONMENT_VARIABLE = 'DDEV_PRIMARY_URL_WITHOUT_PORT';

  /**
   * The path a Vite dev server answers, and only a Vite dev server.
   *
   * The same path the build CLI probes, so the two agree on what counts.
   *
   * @see tools/neo-cli.cjs
   */
  private const PROBE_PATH = '/@vite/client';

  /**
   * Constructs a DevServer.
   *
   * @param \GuzzleHttp\ClientInterface $httpClient
   *   The HTTP client, which is the seam a test fakes the probe through.
   * @param int $port
   *   The dev server port, from settings.
   */
  public function __construct(
    private readonly ClientInterface $httpClient,
    private readonly int $port,
  ) {}

  /**
   * Builds the service with the port from settings.
   *
   * A factory rather than a constructor argument so the settings read happens
   * once, at construction, while the constructor stays plainly injectable from
   * a test.
   */
  public static function create(ClientInterface $httpClient): self {
    return new self($httpClient, (int) NeoBuild::getNeoSetting('port', 5173));
  }

  /**
   * The port the dev server runs on.
   */
  public function getPort(): int {
    return $this->port;
  }

  /**
   * Whether this environment can produce a dev server URL at all.
   */
  public function isAvailable(): bool {
    return $this->getHost() !== NULL;
  }

  /**
   * The browser-facing dev server URL, or NULL when there is none to give.
   *
   * @return string|null
   *   The URL with a trailing slash, or NULL when the DDEV variable is absent.
   */
  public function getUrl(): ?string {
    $host = $this->getHost();
    if ($host === NULL) {
      return NULL;
    }
    return $host . ':' . $this->port . '/';
  }

  /**
   * Why there is no dev server URL, or NULL when there is one.
   */
  public function getUnavailableReason(): ?string {
    if ($this->isAvailable()) {
      return NULL;
    }
    return sprintf(
      '%s is not set. Neo dev mode runs under DDEV and no other environment is supported, so there is no dev server URL to give.',
      self::ENVIRONMENT_VARIABLE,
    );
  }

  /**
   * Whether a Vite dev server is answering on the configured port.
   *
   * An HTTP GET for the Vite client, which only a real Vite server serves —
   * unlike the raw socket connect this replaces, where any process listening on
   * the port counted. Probed over localhost because Drupal and the dev server
   * share a container, which is also what the build CLI does.
   */
  public function isAnswering(): bool {
    try {
      $this->httpClient->request('GET', 'http://localhost:' . $this->port . self::PROBE_PATH, [
        'timeout' => 1,
        'connect_timeout' => 1,
        'http_errors' => FALSE,
      ]);
    }
    catch (\Throwable) {
      return FALSE;
    }
    return TRUE;
  }

  /**
   * The host half of the URL, or NULL when the variable is absent or empty.
   */
  private function getHost(): ?string {
    $host = getenv(self::ENVIRONMENT_VARIABLE);
    if ($host === FALSE || $host === '') {
      return NULL;
    }
    return $host;
  }

}
