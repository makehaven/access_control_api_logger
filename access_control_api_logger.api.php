<?php

use Drupal\Core\Session\AccountInterface;
use Drupal\Core\Url;
use Drupal\user\UserInterface;

/**
 * @file
 * API documentation for access_control_api_logger module.
 */

/**
 * Provides admin links for the member access panel on user pages.
 *
 * @param \Drupal\user\UserInterface $account
 *   The member being viewed.
 * @param \Drupal\Core\Session\AccountInterface $viewer
 *   The current user viewing the page.
 *
 * @return array[]
 *   A list of link definitions. Each entry may contain:
 *   - id: (string) Optional unique ID used for deduplication.
 *   - title: (string|\Drupal\Core\StringTranslation\TranslatableMarkup) Required label.
 *   - url: (\Drupal\Core\Url) Direct URL object.
 *   - route_name/route_parameters/route_options OR uri/url_options: Alternative
 *     ways to build a URL if 'url' is not supplied.
 *   - description: (string|\Drupal\Component\Render\MarkupInterface) Optional
 *     helper text shown under the link.
 *   - category: (string|\Drupal\Core\StringTranslation\TranslatableMarkup)
 *     Optional category heading.
 *   - weight: (int) Sort weight within its category.
 *   - group_weight: (int) Sort weight for the category wrapper.
 *   - attributes: (array) HTML attributes added to the link (target, rel, etc).
 *   - permissions: (string[]) Optional list of permissions required to show the
 *     link.
 *   - access: (bool) Explicit TRUE/FALSE flag to override default visibility.
 */
function hook_access_control_api_logger_user_admin_links(UserInterface $account, AccountInterface $viewer): array {
  $links = [];
  $links[] = [
    'id' => 'example',
    'title' => t('Example link'),
    'url' => Url::fromRoute('entity.user.canonical', ['user' => $account->id()]),
    'description' => t('Describe what this action does.'),
    'category' => t('Demo tools'),
    'weight' => -10,
    'attributes' => ['target' => '_blank'],
  ];
  return $links;
}
