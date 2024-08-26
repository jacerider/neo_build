<?php

declare(strict_types = 1);

namespace Drupal\neo_build;

use Drupal\Core\Cache\CacheBackendInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\DefaultPluginManager;
use Drupal\Core\Plugin\Discovery\YamlDiscovery;
use Drupal\Core\Plugin\Factory\ContainerFactory;

/**
 * Defines a plugin manager to deal with Neo Build Groups.
 */
final class GroupPluginManager extends DefaultPluginManager {

  /**
   * {@inheritdoc}
   */
  protected $defaults = [
    'id' => '',
    'label' => '',
    'description' => '',
    'include' => [],
  ];

  /**
   * Constructs GroupPluginManager object.
   */
  public function __construct(ModuleHandlerInterface $module_handler, CacheBackendInterface $cache_backend) {
    $this->factory = new ContainerFactory($this);
    $this->moduleHandler = $module_handler;
    $this->alterInfo('neo_build_group_info');
    $this->setCacheBackend($cache_backend, 'neo_build_group_plugins');
  }

  /**
   * {@inheritdoc}
   */
  protected function getDiscovery(): YamlDiscovery {
    if (!isset($this->discovery)) {
      $discovery = new YamlDiscovery('neo_build_groups', $this->moduleHandler->getModuleDirectories());
      $discovery->addTranslatableProperty('label', 'label_context');
      $discovery->addTranslatableProperty('description', 'description_context');
      $this->discovery = $discovery;
    }
    return $this->discovery;
  }

}
