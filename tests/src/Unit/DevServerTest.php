<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\neo_build\DevServer;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the one answer to "is the dev server up, and where?".
 *
 * There used to be three, and they disagreed. Drush opened a raw TCP socket to
 * the configured port, so any process answering there counted. The build CLI
 * issued an HTTP GET for /@vite/client, so only a real Vite server counted.
 * And the URL the rewritten assets pointed at had :5173 hard-coded, ignoring
 * the configured port entirely — so on a site that moved the port, both probes
 * agreed the server was up while every asset URL pointed somewhere else.
 */
#[Group('neo_build')]
class DevServerTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);
    parent::tearDown();
  }

  /**
   * Criterion: the URL composes from the DDEV variable and the settings port.
   */
  public function testItComposesTheUrlFromTheVariableAndThePort(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE . '=https://example.ddev.site');

    $this->assertSame('https://example.ddev.site:5173/', $this->devServer()->getUrl());
  }

  /**
   * Criterion: an absent variable is reported, not composed around.
   *
   * The old expression produced ":5173/" out of an unset variable — a URL
   * shaped correctly enough to be used and wrong enough that every asset 404s.
   */
  public function testItReportsTheVariablesAbsenceRatherThanComposingUrl(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);

    $devServer = $this->devServer();

    $this->assertNull($devServer->getUrl(), 'No variable means no URL to give.');
    $this->assertFalse($devServer->isAvailable());

    $reason = $devServer->getUnavailableReason();
    $this->assertIsString($reason);
    $this->assertStringContainsString(DevServer::ENVIRONMENT_VARIABLE, $reason, 'The reason names the variable.');
    $this->assertStringContainsString('DDEV', $reason, 'The reason names the supported environment.');
  }

  /**
   * Criterion: an empty variable counts as absent.
   */
  public function testItTreatsAnEmptyVariableAsAbsent(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE . '=');

    $this->assertNull($this->devServer()->getUrl());
  }

  /**
   * Criterion: the variable is read through getenv().
   *
   * $_ENV is not populated under PHPUnit unless variables_order says so, which
   * is exactly why the old $_ENV read could not be tested at all.
   */
  public function testItReadsTheVariableThroughGetenv(): void {
    unset($_ENV[DevServer::ENVIRONMENT_VARIABLE]);
    putenv(DevServer::ENVIRONMENT_VARIABLE . '=https://only-in-getenv.test');

    $this->assertSame('https://only-in-getenv.test:5173/', $this->devServer()->getUrl());
  }

  /**
   * Criterion: the port comes from settings, retiring the :5173 hardcode.
   */
  public function testItTakesThePortFromSettings(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE . '=https://example.ddev.site');

    $devServer = $this->devServer(port: 5199);

    $this->assertSame(5199, $devServer->getPort());
    $this->assertSame('https://example.ddev.site:5199/', $devServer->getUrl());
  }

  /**
   * Criterion: isAnswering() issues an HTTP GET for /@vite/client.
   */
  public function testItAnswersFromAnHttpGetForTheViteClient(): void {
    $requested = [];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      function (string $method, string $uri) use (&$requested): Response {
        $requested[] = $method . ' ' . $uri;
        return new Response(200);
      },
    );

    $this->assertTrue($this->devServer(client: $client)->isAnswering());
    $this->assertSame(['GET http://localhost:5173/@vite/client'], $requested);
  }

  /**
   * Criterion: a refused connection is not a dev server.
   */
  public function testItDoesNotAnswerWhenTheConnectionFails(): void {
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willThrowException(
      new ConnectException('Connection refused', new Request('GET', 'http://localhost:5173/@vite/client')),
    );

    $this->assertFalse($this->devServer(client: $client)->isAnswering());
  }

  /**
   * Criterion: the probe honours the configured port.
   */
  public function testItProbesTheConfiguredPort(): void {
    $requested = [];
    $client = $this->createMock(ClientInterface::class);
    $client->method('request')->willReturnCallback(
      function (string $method, string $uri) use (&$requested): Response {
        $requested[] = $uri;
        return new Response(200);
      },
    );

    $this->devServer(port: 5199, client: $client)->isAnswering();

    $this->assertSame(['http://localhost:5199/@vite/client'], $requested);
  }

  /**
   * Builds the service under test.
   */
  protected function devServer(int $port = 5173, ?ClientInterface $client = NULL): DevServer {
    return new DevServer($client ?? $this->createMock(ClientInterface::class), $port);
  }

}
