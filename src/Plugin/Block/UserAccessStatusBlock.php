<?php

declare(strict_types=1);

namespace Drupal\access_control_api_logger\Plugin\Block;

use Drupal\access_control_api_logger\Service\AccessStatusEvaluator;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Extension\ModuleHandlerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\Url;
use Drupal\user\UserInterface;
use Symfony\Component\DependencyInjection\ContainerInterface;

/**
 * Displays the stoplight-style access summary and admin links.
 *
 * @Block(
 *   id = "access_control_api_logger_user_status",
 *   admin_label = @Translation("Member access status panel"),
 *   category = @Translation("Access Control")
 * )
 */
class UserAccessStatusBlock extends BlockBase implements ContainerFactoryPluginInterface {

  use StringTranslationTrait;

  /**
   * Evaluator for access statuses.
   */
  protected AccessStatusEvaluator $accessStatusEvaluator;

  /**
   * Module handler for collecting admin links.
   */
  protected ModuleHandlerInterface $moduleHandler;

  /**
   * Current user.
   */
  protected AccountInterface $currentUser;

  /**
   * Route match service.
   */
  protected RouteMatchInterface $routeMatch;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Constructs the block plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccessStatusEvaluator $access_status_evaluator, ModuleHandlerInterface $module_handler, AccountInterface $current_user, RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->accessStatusEvaluator = $access_status_evaluator;
    $this->moduleHandler = $module_handler;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container, array $configuration, $plugin_id, $plugin_definition): self {
    return new static(
      $configuration,
      $plugin_id,
      $plugin_definition,
      $container->get('access_control_api_logger.access_status_evaluator'),
      $container->get('module_handler'),
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager')
    );
  }

  /**
   * {@inheritdoc}
   */
  public function build(): array {
    $account = $this->getRoutedUser();
    if (!$account) {
      return [];
    }

    $status = $this->accessStatusEvaluator->evaluate($account);
    $link_groups = $this->buildAdminLinkGroups($account);

    return [
      '#theme' => 'access_control_user_status_block',
      '#summary' => $status['summary'],
      '#items' => $status['items'],
      '#link_groups' => $link_groups,
      '#attached' => [
        'library' => ['access_control_api_logger/user_status_panel'],
      ],
      '#cache' => [
        'contexts' => ['route', 'url.path', 'user.permissions'],
        'tags' => $account->getCacheTags(),
      ],
    ];
  }

  /**
   * Builds grouped admin links provided by other modules.
   */
  protected function buildAdminLinkGroups(UserInterface $account): array {
    $link_sets = $this->moduleHandler->invokeAll('access_control_api_logger_user_admin_links', [$account, $this->currentUser]);
    $links = [];
    $seen = [];

    foreach ($link_sets as $set) {
      if (!is_array($set)) {
        continue;
      }
      foreach ($set as $definition) {
        if (!is_array($definition)) {
          continue;
        }
        $normalized = $this->normalizeLinkDefinition($definition);
        if (!$normalized) {
          continue;
        }
        $id = $normalized['id'] ?? NULL;
        if ($id && isset($seen[$id])) {
          continue;
        }
        if ($id) {
          $seen[$id] = TRUE;
        }
        $links[] = $normalized;
      }
    }

    $this->moduleHandler->alter('access_control_api_logger_user_admin_links', $links, $account, $this->currentUser);

    usort($links, static function (array $a, array $b): int {
      return $a['weight'] <=> $b['weight'] ?: strcasecmp((string) $a['title'], (string) $b['title']);
    });

    $grouped = [];
    foreach ($links as $link) {
      $category = $link['category'] ?? $this->t('Admin Links');
      $category_label = (string) $category;
      $group_key = md5($category_label);

      if (!isset($grouped[$group_key])) {
        $grouped[$group_key] = [
          'label' => $category,
          'links' => [],
          'weight' => $link['group_weight'] ?? 0,
        ];
      }
      $grouped[$group_key]['links'][] = $link;
    }

    usort($grouped, static function (array $a, array $b): int {
      $comparison = ($a['weight'] ?? 0) <=> ($b['weight'] ?? 0);
      if ($comparison !== 0) {
        return $comparison;
      }
      return strcasecmp((string) $a['label'], (string) $b['label']);
    });

    return $grouped;
  }

  /**
   * Normalizes an incoming link definition.
   */
  protected function normalizeLinkDefinition(array $definition): ?array {
    if (isset($definition['access']) && !$definition['access']) {
      return NULL;
    }

    if (!empty($definition['permissions'])) {
      foreach ((array) $definition['permissions'] as $permission) {
        if (!$this->currentUser->hasPermission($permission)) {
          return NULL;
        }
      }
    }

    if (empty($definition['title'])) {
      return NULL;
    }

    $url = $definition['url'] ?? NULL;
    if (!$url && !empty($definition['route_name'])) {
      $url = Url::fromRoute(
        $definition['route_name'],
        $definition['route_parameters'] ?? [],
        $definition['route_options'] ?? []
      );
    }
    elseif (!$url && !empty($definition['uri'])) {
      $url = Url::fromUri($definition['uri'], $definition['url_options'] ?? []);
    }

    if (is_string($url)) {
      $url = Url::fromUri($url);
    }

    if (!$url instanceof Url) {
      return NULL;
    }

    if (!empty($definition['attributes'])) {
      $options = $url->getOptions();
      $options['attributes'] = ($options['attributes'] ?? []) + $definition['attributes'];
      $url->setOptions($options);
    }

    return [
      'id' => $definition['id'] ?? NULL,
      'title' => $definition['title'],
      'url' => $url,
      'description' => $definition['description'] ?? NULL,
      'category' => $definition['category'] ?? NULL,
      'weight' => (int) ($definition['weight'] ?? 0),
      'group_weight' => (int) ($definition['group_weight'] ?? 0),
    ];
  }

  /**
   * Gets the user entity referenced by the current route.
   */
  protected function getRoutedUser(): ?UserInterface {
    $route_user = $this->routeMatch->getParameter('user');

    if ($route_user instanceof UserInterface) {
      return $route_user;
    }
    if (is_numeric($route_user)) {
      return $this->entityTypeManager->getStorage('user')->load((int) $route_user);
    }
    return NULL;
  }

}
