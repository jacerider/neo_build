<?php

declare(strict_types=1);

namespace Drupal\Tests\neo_build\Unit;

use Drupal\Core\Extension\ModuleExtensionList;
use Drupal\Core\File\FileSystemInterface;
use Drupal\neo_build\Commands\DrushCommands;
use Drupal\neo_build\DevServer;
use Drupal\neo_build\NeoBuild;
use Drupal\neo_build\ProjectRootInterface;
use Drupal\Tests\UnitTestCase;
use GuzzleHttp\ClientInterface;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use PHPUnit\Framework\Attributes\Group;

/**
 * Covers the four status branches and the dev-mode refusal.
 *
 * None of these four branches had ever been covered, because the probe did a
 * real socket connect inside the command — there was no seam to stand a test
 * on. The command now reads neo_build.dev_server, and the dev server reads an
 * HTTP client, so every branch is reachable.
 *
 * The command is built without its constructor, as DrushScopesTest does: only
 * the collaborators each behaviour actually touches are set, and a test that
 * had to satisfy thirteen constructor arguments to assert on a string would be
 * asserting the mocks.
 */
#[Group('neo_build')]
class DrushDevServerStatusTest extends UnitTestCase {

  /**
   * {@inheritdoc}
   */
  protected function tearDown(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);
    parent::tearDown();
  }

  /**
   * Criterion: dev flag on and a server answering reports DEV.
   */
  public function testItReportsDevWhenTheFlagIsOnAndTheServerAnswers(): void {
    $status = $this->statusRows(dev: TRUE, answering: TRUE);

    $this->assertStringStartsWith('DEV', $status['status']);
    $this->assertTrue($status['dev']);
    $this->assertTrue($status['dev_server_up']);
  }

  /**
   * Criterion: dev flag on with nothing answering reports STALE.
   */
  public function testItReportsStaleWhenNothingAnswers(): void {
    $status = $this->statusRows(dev: TRUE, answering: FALSE);

    $this->assertStringStartsWith('STALE', $status['status']);
    $this->assertTrue($status['dev']);
    $this->assertFalse($status['dev_server_up']);
  }

  /**
   * Criterion: a server answering with the flag off reports ORPHANED.
   */
  public function testItReportsOrphanedWhenDrupalIsNotUsingTheServer(): void {
    $status = $this->statusRows(dev: FALSE, answering: TRUE);

    $this->assertStringStartsWith('ORPHANED', $status['status']);
  }

  /**
   * Criterion: neither reports PROD.
   */
  public function testItReportsProdWhenNeitherIsTrue(): void {
    $status = $this->statusRows(dev: FALSE, answering: FALSE);

    $this->assertStringStartsWith('PROD', $status['status']);
  }

  /**
   * Criterion: the URL field reports the cause when the variable is absent.
   */
  public function testItReportsTheVariablesAbsenceInTheUrlField(): void {
    $status = $this->statusRows(dev: FALSE, answering: FALSE, host: NULL);

    $this->assertStringContainsString(DevServer::ENVIRONMENT_VARIABLE, (string) $status['dev_server_url']);
    $this->assertStringNotContainsString(':5173/', (string) $status['dev_server_url']);
  }

  /**
   * Criterion: dev:enable refuses without the DDEV variable.
   */
  public function testItRefusesDevEnableWithoutTheDdevVariable(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);

    $commands = $this->commands(
      neoBuild: $this->neoBuild(dev: FALSE),
      devServer: $this->devServer(answering: FALSE),
    );

    $this->expectException(\RuntimeException::class);
    $this->expectExceptionMessageMatches('/' . DevServer::ENVIRONMENT_VARIABLE . '/');

    $commands->neoBuildDevEnable();
  }

  /**
   * Criterion: the refusal names DDEV as the supported environment.
   */
  public function testTheRefusalNamesDdev(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);

    $commands = $this->commands(
      neoBuild: $this->neoBuild(dev: FALSE),
      devServer: $this->devServer(answering: FALSE),
    );

    try {
      $commands->neoBuildDevEnable();
      $this->fail('dev:enable did not refuse.');
    }
    catch (\RuntimeException $e) {
      $this->assertStringContainsString('DDEV', $e->getMessage());
    }
  }

  /**
   * Criterion: a refusal writes nothing at all.
   *
   * No state write, no lock file, no pre-commit hook. Dev mode never turns on
   * into a state where every asset 404s.
   */
  public function testItWritesNothingWhenItRefuses(): void {
    putenv(DevServer::ENVIRONMENT_VARIABLE);

    $neoBuild = $this->createMock(NeoBuild::class);
    $neoBuild->method('isDevMode')->willReturn(FALSE);
    $neoBuild->expects($this->never())->method('setDevMode');

    $fileSystem = $this->createMock(FileSystemInterface::class);
    $fileSystem->expects($this->never())->method('saveData');
    $fileSystem->expects($this->never())->method('chmod');

    $commands = $this->commands(
      neoBuild: $neoBuild,
      devServer: $this->devServer(answering: FALSE),
      fileSystem: $fileSystem,
    );

    try {
      $commands->neoBuildDevEnable();
    }
    catch (\RuntimeException) {
      // The refusal is the point; the assertions are the never() expectations.
    }
  }

  /**
   * Criterion: the command's own socket probe is gone.
   */
  public function testTheCommandNoLongerProbesTheSocketItself(): void {
    $source = file_get_contents((new \ReflectionClass(DrushCommands::class))->getFileName());

    $this->assertStringNotContainsString('fsockopen', $source, 'The raw socket probe belongs to nobody now.');
    $this->assertFalse(
      method_exists(DrushCommands::class, 'devServerAnswering'),
      'The command reads neo_build.dev_server instead of answering the question itself.',
    );
  }

  /**
   * Runs the status command and returns its rows.
   */
  protected function statusRows(bool $dev, bool $answering, ?string $host = 'https://wps.ddev.site'): array {
    if ($host === NULL) {
      putenv(DevServer::ENVIRONMENT_VARIABLE);
    }
    else {
      putenv(DevServer::ENVIRONMENT_VARIABLE . '=' . $host);
    }

    $commands = $this->commands(
      neoBuild: $this->neoBuild(dev: $dev),
      devServer: $this->devServer(answering: $answering),
    );

    return $commands->neoBuildStatus()->getArrayCopy();
  }

  /**
   * Builds the command with only the collaborators under test.
   */
  protected function commands(NeoBuild $neoBuild, DevServer $devServer, ?FileSystemInterface $fileSystem = NULL): DrushCommands {
    $projectRoot = $this->createMock(ProjectRootInterface::class);
    $projectRoot->method('getRoot')->willReturn(sys_get_temp_dir() . '/neo-build-no-such-root');

    $commands = (new \ReflectionClass(DrushCommands::class))->newInstanceWithoutConstructor();

    $this->setProperty($commands, 'neoBuild', $neoBuild);
    $this->setProperty($commands, 'devServer', $devServer);
    $this->setProperty($commands, 'projectRoot', $projectRoot);
    $this->setProperty($commands, 'fileSystem', $fileSystem ?? $this->createMock(FileSystemInterface::class));
    $this->setProperty($commands, 'moduleExtensionList', $this->createMock(ModuleExtensionList::class));
    $this->setProperty($commands, 'appRoot', sys_get_temp_dir());

    return $commands;
  }

  /**
   * A dev server whose probe answers, or does not.
   */
  protected function devServer(bool $answering): DevServer {
    $client = $this->createMock(ClientInterface::class);
    if ($answering) {
      $client->method('request')->willReturn(new Response(200));
    }
    else {
      $client->method('request')->willThrowException(
        new ConnectException('Connection refused', new Request('GET', 'http://localhost:5173/@vite/client')),
      );
    }

    return new DevServer($client, 5173);
  }

  /**
   * A build service reporting the given dev flag.
   */
  protected function neoBuild(bool $dev): NeoBuild {
    $neoBuild = $this->createMock(NeoBuild::class);
    $neoBuild->method('isDevMode')->willReturn($dev);
    $neoBuild->method('getScope')->willReturn('front');

    return $neoBuild;
  }

  /**
   * Sets a promoted readonly property on the constructor-less command.
   */
  protected function setProperty(DrushCommands $commands, string $name, mixed $value): void {
    $property = new \ReflectionProperty(DrushCommands::class, $name);
    $property->setValue($commands, $value);
  }

}
