<?php

namespace Drupal\access_control_api_logger\Service;

use Drupal\Core\Config\ConfigFactoryInterface;
use Drupal\Core\Config\ImmutableConfig;
use Drupal\Core\Entity\EntityStorageInterface;
use Drupal\Core\Entity\EntityTypeManagerInterface;
use Drupal\Core\Logger\LoggerChannelFactoryInterface;
use Drupal\Core\Logger\LoggerChannelInterface;
use Drupal\node\NodeInterface;
use Drupal\taxonomy\TermInterface;
use Drupal\user\UserInterface;

/**
 * Builds a JSON payload compatible with the Maker Access Control UI store.
 */
class FallbackStoreBuilder {

  /**
   * User storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $userStorage;

  /**
   * Node storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $nodeStorage;

  /**
   * Term storage.
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface
   */
  protected EntityStorageInterface $termStorage;

  /**
   * Optional profile storage (when the profile module is enabled).
   *
   * @var \Drupal\Core\Entity\EntityStorageInterface|null
   */
  protected ?EntityStorageInterface $profileStorage;

  /**
   * Module logger.
   *
   * @var \Drupal\Core\Logger\LoggerChannelInterface
   */
  protected LoggerChannelInterface $logger;

  /**
   * Config factory.
   *
   * @var \Drupal\Core\Config\ConfigFactoryInterface
   */
  protected ConfigFactoryInterface $configFactory;

  /**
   * Constructs a new fallback store builder.
   */
  public function __construct(
    EntityTypeManagerInterface $entity_type_manager,
    ConfigFactoryInterface $config_factory,
    LoggerChannelFactoryInterface $logger_factory,
  ) {
    $this->userStorage = $entity_type_manager->getStorage('user');
    $this->nodeStorage = $entity_type_manager->getStorage('node');
    $this->termStorage = $entity_type_manager->getStorage('taxonomy_term');
    $this->profileStorage = $entity_type_manager->hasDefinition('profile') ? $entity_type_manager->getStorage('profile') : NULL;
    $this->configFactory = $config_factory;
    $this->logger = $logger_factory->get('access_control_api_logger');
  }

  /**
   * Builds the export payload.
   */
  public function build(): array {
    $config = $this->configFactory->get('access_control_api_logger.settings');
    $user_bundle = $this->collectUsers($config);
    $tool_bundle = $this->collectTools($config);

    $assignments = $this->collectAssignments(
      $user_bundle['entity_to_store_id'],
      $tool_bundle['term_map'],
      $config,
    );

    return [
      'users' => $user_bundle['records'],
      'tools' => $tool_bundle['records'],
      'assignments' => $assignments,
    ];
  }

  /**
   * Gather eligible users keyed by entity id.
   */
  protected function collectUsers(ImmutableConfig $config): array {
    $query = $this->userStorage->getQuery()
      ->condition('status', 1)
      ->accessCheck(FALSE);

    $include_names = (bool) ($config->get('fallback_include_user_names') ?? FALSE);
    $include_email = (bool) ($config->get('fallback_include_user_email') ?? FALSE);

    $uids = $query->execute();
    if (empty($uids)) {
      return [
        'records' => [],
        'entity_to_store_id' => [],
      ];
    }

    $records = [];
    $entity_to_store_id = [];
    foreach ($this->userStorage->loadMultiple($uids) as $user) {
      if (!$user instanceof UserInterface) {
        continue;
      }
      if (!$this->userIsEligible($user, $config)) {
        continue;
      }
      $card_serial = $this->resolveCardSerial($user);
      if ($card_serial === '') {
        continue;
      }

      $store_id = $this->buildUserStoreId($user);
      $entity_to_store_id[$user->id()] = $store_id;
      $record = [
        'id' => $store_id,
        'card_serial' => $card_serial,
        'uuid' => $user->uuid(),
      ];

      if ($include_names) {
        $record['first_name'] = $this->safeUserFieldValue($user, 'field_first_name');
        $record['last_name'] = $this->safeUserFieldValue($user, 'field_last_name');
      }

      if ($include_email) {
        $record['email'] = $user->getEmail() ?? '';
      }

      $records[$store_id] = $record;
    }

    ksort($records, SORT_STRING);

    return [
      'records' => array_values($records),
      'entity_to_store_id' => $entity_to_store_id,
    ];
  }

  /**
   * Gather all permissions/badges as tool entries.
   */
  protected function collectTools(ImmutableConfig $config): array {
    $terms = $this->termStorage->loadByProperties(['vid' => 'badges']);
    if (empty($terms)) {
      return [
        'records' => [],
        'term_map' => [],
      ];
    }

    $records = [];
    $term_map = [];
    $allowed_permissions = $this->getLimitedPermissionMap($config);

    $missing_terms = [];
    foreach ($terms as $term) {
      if (!$term instanceof TermInterface) {
        continue;
      }

      $raw_permission_id = $term->hasField('field_badge_text_id') ? (string) $term->get('field_badge_text_id')->value : '';
      $permission_id = $this->normalizePermissionId($raw_permission_id);
      if ($permission_id === '') {
        $missing_terms[] = $term->id();
        continue;
      }
      if ($allowed_permissions && !isset($allowed_permissions[$permission_id])) {
        continue;
      }

      $tool_id = $this->buildToolId($permission_id, (int) $term->id());
      $record = [
        'id' => $tool_id,
        'name' => (string) $term->getName(),
        'badge_name' => $permission_id,
        'reader_device_id' => $tool_id,
        'activator_device_id' => $tool_id,
        'device_id' => $tool_id,
      ];
      $records[$tool_id] = $record;
      $term_map[$term->id()] = [
        'tool_id' => $tool_id,
        'permission_id' => $permission_id,
      ];
    }

    ksort($records, SORT_STRING);

    if (!empty($missing_terms)) {
      $examples = implode(', ', array_slice($missing_terms, 0, 10));
      $this->logger->warning('Skipped @count badge terms lacking field_badge_text_id (examples: @examples).', [
        '@count' => count($missing_terms),
        '@examples' => $examples,
      ]);
    }

    return [
      'records' => array_values($records),
      'term_map' => $term_map,
    ];
  }

  /**
   * Collect user/tool assignments from badge request nodes.
   */
  protected function collectAssignments(array $user_id_map, array $term_map, ImmutableConfig $config): array {
    if (empty($user_id_map) || empty($term_map)) {
      return [];
    }

    $query = $this->nodeStorage->getQuery()
      ->condition('type', 'badge_request')
      ->accessCheck(FALSE);

    $check_badge_status = $config->get('check_badge_status');
    if ($check_badge_status === NULL || $check_badge_status) {
      $query->condition('field_badge_status.value', 'active');
    }

    $nids = $query->execute();
    if (empty($nids)) {
      return [];
    }

    if (($config->get('check_user_has_permission') ?? TRUE) === FALSE) {
      $this->logger->warning('Fallback export still requires explicit assignments even though check_user_has_permission is disabled.');
    }

    $assignments = [];
    $seen = [];

    foreach ($this->nodeStorage->loadMultiple($nids) as $node) {
      if (!$node instanceof NodeInterface) {
        continue;
      }
      if (!$node->hasField('field_member_to_badge') || $node->get('field_member_to_badge')->isEmpty()) {
        continue;
      }
      if (!$node->hasField('field_badge_requested') || $node->get('field_badge_requested')->isEmpty()) {
        continue;
      }

      $user_entity_id = (int) $node->get('field_member_to_badge')->target_id;
      $term_id = (int) $node->get('field_badge_requested')->target_id;

      if (!isset($user_id_map[$user_entity_id], $term_map[$term_id])) {
        continue;
      }

      $user_store_id = $user_id_map[$user_entity_id];
      $tool_id = $term_map[$term_id]['tool_id'];

      $pair_key = $user_store_id . ':' . $tool_id;
      if (isset($seen[$pair_key])) {
        continue;
      }
      $seen[$pair_key] = TRUE;
      $assignments[] = [$user_store_id, $tool_id];
    }

    usort($assignments, static function (array $a, array $b): int {
      return [$a[0], $a[1]] <=> [$b[0], $b[1]];
    });

    return $assignments;
  }

  /**
   * Determine whether a user should be exported.
   */
  protected function userIsEligible(UserInterface $user, ImmutableConfig $config): bool {
    $check_user_status = $config->get('check_user_status');
    if ($check_user_status !== NULL && !$check_user_status) {
      return TRUE;
    }

    $check_pause_payment = $config->get('check_pause_payment');
    if ($check_pause_payment === NULL || $check_pause_payment) {
      if ($this->fieldValueIsTruthy($user, 'field_chargebee_payment_pause')) {
        return FALSE;
      }
      if ($this->fieldValueIsTruthy($user, 'field_manual_pause')) {
        return FALSE;
      }
      if ($this->fieldValueIsTruthy($user, 'field_payment_failed')) {
        return FALSE;
      }
    }

    if ($this->fieldValueEquals($user, 'field_access_override', 'deny')) {
      return FALSE;
    }

    $allowed_roles = ['member', 'services', 'instructor'];
    $roles = $user->getRoles();
    if (empty(array_intersect($allowed_roles, $roles))) {
      return FALSE;
    }

    return TRUE;
  }

  /**
   * Resolve the card serial for a user or their primary profile.
   */
  protected function resolveCardSerial(UserInterface $user): string {
    if ($user->hasField('field_card_serial_number') && !$user->get('field_card_serial_number')->isEmpty()) {
      $value = trim((string) $user->get('field_card_serial_number')->value);
      if ($value !== '') {
        return $value;
      }
    }

    if ($this->profileStorage) {
      $query = $this->profileStorage->getQuery()
        ->condition('type', 'main')
        ->condition('uid', $user->id())
        ->range(0, 1)
        ->accessCheck(FALSE);

      $profile_ids = $query->execute();
      if (!empty($profile_ids)) {
        foreach ($this->profileStorage->loadMultiple($profile_ids) as $profile) {
          if ($profile->hasField('field_card_serial_number') && !$profile->get('field_card_serial_number')->isEmpty()) {
            $value = trim((string) $profile->get('field_card_serial_number')->value);
            if ($value !== '') {
              return $value;
            }
          }
        }
      }
    }

    return '';
  }

  /**
   * Build a deterministic identifier for the JSON store.
   */
  protected function buildUserStoreId(UserInterface $user): string {
    return $user->uuid();
  }

  /**
   * Safely extract a user field value.
   */
  protected function safeUserFieldValue(UserInterface $user, string $field_name): string {
    if ($user->hasField($field_name) && !$user->get($field_name)->isEmpty()) {
      return (string) $user->get($field_name)->value;
    }
    return '';
  }

  /**
   * Normalize a permission identifier for downstream consumers.
   */
  protected function normalizePermissionId(?string $value): string {
    $normalized = strtolower(trim((string) $value));
    $normalized = preg_replace('/[^a-z0-9._-]+/', '_', $normalized);
    return trim($normalized, '_');
  }

  /**
   * Build a unique tool identifier.
   */
  protected function buildToolId(string $permission_id, int $term_id): string {
    if ($permission_id === '') {
      return 'perm.' . $term_id;
    }
    return 'perm.' . $permission_id . '.' . $term_id;
  }

  /**
   * Determine whether a boolean-ish field is set.
   */
  protected function fieldValueIsTruthy(UserInterface $user, string $field_name): bool {
    if (!$user->hasField($field_name) || $user->get($field_name)->isEmpty()) {
      return FALSE;
    }
    $value = $user->get($field_name)->value;
    if ($value === NULL) {
      return FALSE;
    }
    $value = is_string($value) ? strtolower(trim($value)) : $value;
    return $value === 1 || $value === '1' || $value === TRUE || $value === 'true';
  }

  /**
   * Compare a field value against an expected string.
   */
  protected function fieldValueEquals(UserInterface $user, string $field_name, string $expected): bool {
    if (!$user->hasField($field_name) || $user->get($field_name)->isEmpty()) {
      return FALSE;
    }
    $value = strtolower((string) $user->get($field_name)->value);
    return $value === strtolower($expected);
  }

  /**
   * Return a lookup table of permission IDs that should be exported.
   */
  protected function getLimitedPermissionMap(ImmutableConfig $config): array {
    $raw = (string) ($config->get('fallback_limit_permissions') ?? '');
    if ($raw === '') {
      return [];
    }

    $fragments = preg_split('/[\r\n,]+/', $raw);
    $map = [];
    foreach ($fragments as $fragment) {
      $normalized = $this->normalizePermissionId($fragment);
      if ($normalized !== '') {
        $map[$normalized] = TRUE;
      }
    }

    return $map;
  }
}
