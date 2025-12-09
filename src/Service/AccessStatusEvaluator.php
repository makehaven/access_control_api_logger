<?php

declare(strict_types=1);

namespace Drupal\access_control_api_logger\Service;

use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\StringTranslation\StringTranslationTrait;
use Drupal\Core\StringTranslation\TranslationInterface;
use Drupal\user\UserInterface;

/**
 * Evaluates a member's access readiness and blocking reasons.
 */
class AccessStatusEvaluator {

  use StringTranslationTrait;

  /**
   * Roles that grant access to makerspace hardware.
   *
   * @var string[]
   */
  protected array $allowedRoles;

  /**
   * Entity type manager.
   */
  protected EntityTypeManagerInterface $entityTypeManager;

  /**
   * Cached badge term ids keyed by permission id.
   *
   * @var array<string, int|null>
   */
  protected array $badgeTermCache = [];

  /**
   * AccessStatusEvaluator constructor.
   */
  public function __construct(TranslationInterface $translation, EntityTypeManagerInterface $entity_type_manager, array $allowed_roles = []) {
    $this->stringTranslation = $translation;
    $this->entityTypeManager = $entity_type_manager;
    $this->allowedRoles = $allowed_roles ?: ['member', 'services', 'instructor'];
  }

  /**
   * Builds the state summary for a user account.
   *
   * @param \Drupal\user\UserInterface $account
   *   The user being evaluated.
   *
   * @return array
   *   Structured data describing the access state.
   */
  public function evaluate(UserInterface $account): array {
    $items = [];
    $items[] = $this->buildFlagItem(
      'chargebee_pause',
      $this->t('Chargebee Pause'),
      $this->isFieldEnabled($account, 'field_chargebee_payment_pause'),
      $this->t('Chargebee payment pause is active.'),
      $this->t('Chargebee billing is active.')
    );
    $items[] = $this->buildFlagItem(
      'manual_pause',
      $this->t('Manual Pause'),
      $this->isFieldEnabled($account, 'field_manual_pause'),
      $this->t('Manual pause prevents access.'),
      $this->t('Manual pause disabled.')
    );
    $items[] = $this->buildFlagItem(
      'payment_failed',
      $this->t('Payment Failure'),
      $this->isFieldEnabled($account, 'field_payment_failed'),
      $this->t('Latest membership payment failed.'),
      $this->t('No payment failures.')
    );
    $items[] = $this->buildFlagItem(
      'access_override',
      $this->t('Access Override'),
      $this->isAccessOverrideDenied($account),
      $this->t('Access override denies entry.'),
      $this->t('No access override.')
    );
    $items[] = $this->buildRoleItem($account);
    $items[] = $this->buildDoorBadgeItem($account);

    $blocking = array_values(array_filter($items, static fn(array $item): bool => !empty($item['blocks_access'])));
    $summary = [
      'state' => empty($blocking) ? 'ok' : 'blocked',
      'label' => empty($blocking) ? $this->t('Key Access Ready') : $this->t('Key Access Blocked'),
      'message' => empty($blocking) ? $this->t('No blocking flags detected.') : $blocking[0]['message'],
      'blocking_messages' => array_map(static fn(array $item) => $item['message'], $blocking),
    ];

    return [
      'summary' => $summary,
      'items' => $items,
    ];
  }

  /**
   * Builds a status entry for boolean fields.
   */
  protected function buildFlagItem(string $id, string|\Stringable $label, bool $flagged, string|\Stringable $blocked_message, string|\Stringable $ok_message): array {
    return [
      'id' => $id,
      'label' => (string) $label,
      'state' => $flagged ? 'blocked' : 'ok',
      'message' => (string) ($flagged ? $blocked_message : $ok_message),
      'blocks_access' => $flagged,
    ];
  }

  /**
   * Builds the role-based status entry.
   */
  protected function buildRoleItem(UserInterface $account): array {
    $roles = $account->getRoles();
    $allowed = array_intersect($this->allowedRoles, $roles);
    $has_role = !empty($allowed);

    if ($has_role) {
      $message = $this->t('Member has an access role (@roles).', ['@roles' => $this->formatRoles($allowed)]);
    }
    else {
      $message = $this->t('Add one of: @roles', ['@roles' => $this->formatRoles($this->allowedRoles)]);
    }

    return [
      'id' => 'allowed_roles',
      'label' => $this->t('Maker Roles'),
      'state' => $has_role ? 'ok' : 'blocked',
      'message' => $message,
      'blocks_access' => !$has_role,
      'details' => $this->formatRoles($roles),
    ];
  }

  /**
   * Builds the door badge status entry.
   */
  protected function buildDoorBadgeItem(UserInterface $account): array {
    $has_badge = $this->userHasActiveBadge($account, 'door');

    return [
      'id' => 'door_badge',
      'label' => $this->t('Door Access'),
      'state' => $has_badge ? 'ok' : 'blocked',
      'message' => $has_badge ? $this->t('Door badge is active.') : $this->t('Door badge missing or inactive.'),
      'blocks_access' => !$has_badge,
    ];
  }

  /**
   * Checks if a boolean field is enabled on the account.
   */
  protected function isFieldEnabled(UserInterface $account, string $field_name): bool {
    if (!$account->hasField($field_name)) {
      return FALSE;
    }
    $value = $account->get($field_name)->value;
    return $value === '1' || $value === 1 || $value === TRUE;
  }

  /**
   * Determines whether the access override denies access.
   */
  protected function isAccessOverrideDenied(UserInterface $account): bool {
    if (!$account->hasField('field_access_override')) {
      return FALSE;
    }
    $value = $account->get('field_access_override')->value;
    return $value === 'deny';
  }

  /**
   * Formats a list of roles for display.
   *
   * @param string[] $roles
   *   Role machine names.
   */
  protected function formatRoles(array $roles): string {
    if (empty($roles)) {
      return (string) $this->t('none');
    }
    return implode(', ', array_map(static fn(string $role): string => $role, $roles));
  }

  /**
   * Determines if the account has an active badge for the permission id.
   */
  protected function userHasActiveBadge(UserInterface $account, string $permission_id): bool {
    if ($account->hasRole('services')) {
      return TRUE;
    }
    if ($permission_id === '') {
      return FALSE;
    }
    $term_id = $this->getBadgeTermId($permission_id);
    if (!$term_id) {
      return FALSE;
    }

    $query = $this->entityTypeManager->getStorage('node')->getQuery();
    $query->condition('type', 'badge_request')
      ->condition('field_badge_requested', $term_id)
      ->condition('field_badge_status.value', 'active')
      ->accessCheck(FALSE)
      ->range(0, 1);

    $user_group = $query->orConditionGroup()
      ->condition('field_member_to_badge', $account->id());
    $user_group->condition('field_member_badge_reference', $account->id());
    $query->condition($user_group);
    $nids = $query->execute();
    return !empty($nids);
  }

  /**
   * Fetches the taxonomy term id for a badge permission id.
   */
  protected function getBadgeTermId(string $permission_id): ?int {
    if (array_key_exists($permission_id, $this->badgeTermCache)) {
      return $this->badgeTermCache[$permission_id];
    }

    $query = $this->entityTypeManager->getStorage('taxonomy_term')->getQuery();
    $query->condition('vid', 'badges')
      ->condition('field_badge_text_id', $permission_id)
      ->accessCheck(FALSE)
      ->range(0, 1);
    $tids = $query->execute();
    $tid = $tids ? (int) reset($tids) : NULL;
    $this->badgeTermCache[$permission_id] = $tid;
    return $tid;
  }

}
