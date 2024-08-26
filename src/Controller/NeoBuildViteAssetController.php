<?php

declare(strict_types=1);

namespace Drupal\neo_build\Controller;

use Drupal\Core\Controller\ControllerBase;
use Drupal\neo_build\Build;
use GuzzleHttp\Client;
use Psr\Http\Client\ClientInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Returns responses for Neo | Build routes.
 */
final class NeoBuildViteAssetController extends ControllerBase {

  /**
   * The controller constructor.
   */
  public function __construct(
    private readonly Client $httpClient,
  ) {}

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container): self {
    return new self(
      $container->get('http_client'),
    );
  }

  /**
   * Builds the response.
   */
  public function __invoke(Request $request) {
    $asset = $request->query->get('asset');
    // ksm($_SERVER['SERVER_NAME']);
    // return [];
    $r = $this->httpClient->get('https://' . $_SERVER['SERVER_NAME'] . ':5173' . $asset);
    // $r = $this->httpClient->get($_SERVER['SERVER_NAME'] . 'https://localhost:5173/' . $asset);
    // ksm($r->getHeaders());
    // return [];
    $headers = $r->getHeaders();
    ksm($headers);
    return [];
    // $headers['Content-Type'] = ['application/javascript'];
    // $headers['Content-Type'] = ['text/css'];
    $response = new Response($r->getBody()->getContents(), $r->getStatusCode(), $headers);

    // ksm($response->headers->all());
    // return [];
    return $response;
  }

}
