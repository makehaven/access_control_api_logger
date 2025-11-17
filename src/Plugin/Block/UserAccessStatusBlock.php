<?php

declare(strict_types=1);

namespace Drupal\access_control_api_logger\Plugin\Block;

use Drupal\access_control_api_logger\Service\AccessStatusEvaluator;
use Drupal\Core\Block\BlockBase;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Plugin\ContainerFactoryPluginInterface;
use Drupal\Core\Routing\RouteMatchInterface;
use Drupal\Core\Session\AccountInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\makerspace_user_links\Service\UserLinkManager;
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
   * Makerspace user link manager.
   */
  protected UserLinkManager $linkManager;

  /**
   * Constructs the block plugin.
   */
  public function __construct(array $configuration, $plugin_id, $plugin_definition, AccessStatusEvaluator $access_status_evaluator, AccountInterface $current_user, RouteMatchInterface $route_match, EntityTypeManagerInterface $entity_type_manager, UserLinkManager $link_manager) {
    parent::__construct($configuration, $plugin_id, $plugin_definition);
    $this->accessStatusEvaluator = $access_status_evaluator;
    $this->currentUser = $current_user;
    $this->routeMatch = $route_match;
    $this->entityTypeManager = $entity_type_manager;
    $this->linkManager = $link_manager;
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
      $container->get('current_user'),
      $container->get('current_route_match'),
      $container->get('entity_type.manager'),
      $container->get('makerspace_user_links.link_manager')
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
    $link_groups = $this->linkManager->getGroupedLinks($account, $this->currentUser);

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
