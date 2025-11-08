<?php

namespace Drupal\access_control_api_logger\Controller;

use Drupal\access_control_api_logger\Service\FallbackStoreCache;
use Drupal\Core\Controller\ControllerBase;
use Drupal\taxonomy\Entity\Term;
use Drupal\user\Entity\User;
use Symfony\Component\DependencyInjection\ContainerInterface;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Controller for Access Control API Logger.
 */
class AccessControlApiLoggerController extends ControllerBase {

  /**
   * Cached fallback store builder.
   *
   * @var \Drupal\access_control_api_logger\Service\FallbackStoreCache
   */
  protected FallbackStoreCache $fallbackStoreCache;

  /**
   * AccessControlApiLoggerController constructor.
   */
  public function __construct(FallbackStoreCache $fallback_store_cache) {
    $this->fallbackStoreCache = $fallback_store_cache;
  }

  /**
   * {@inheritdoc}
   */
  public static function create(ContainerInterface $container) {
    return new static(
      $container->get('access_control_api_logger.fallback_store_cache')
    );
  }

  /**
   * Handles access control request logging by UUID.
   */
  public function handleRequest($uuid, $permission_id, Request $request) {
    return $this->logAccessRequest($uuid, $permission_id, 'uuid', $request);
  }

  /**
   * Handles access control request logging by serial number.
   */
  public function handleSerialRequest($serial, $permission_id, Request $request) {
    // \Drupal::logger('access_control_api_logger')->notice('Received serial request for Serial: @serial and Permission ID: @permission_id', [
    //   '@serial' => $serial,
    //   '@permission_id' => $permission_id,
    // ]);
    return $this->logAccessRequest($serial, $permission_id, 'serial', $request);
  }

  /**
   * Handles access control request logging by email.
   */
  public function handleEmailRequest($email, $permission_id, Request $request) {
    // \Drupal::logger('access_control_api_logger')->notice('Received email request for Email: @email and Permission ID: @permission_id', [
    //   '@email' => $email,
    //   '@permission_id' => $permission_id,
    // ]);
    return $this->logAccessRequest($email, $permission_id, 'email', $request);
  }

  /**
   * Exports the fallback Maker UI store as JSON when the shared code matches.
   */
  public function exportFallbackStore(Request $request) {
    $config = $this->config('access_control_api_logger.settings');
    $expected_code = trim((string) $config->get('fallback_shared_code'));

    if ($expected_code === '') {
      return new JsonResponse(['error' => 'Fallback export disabled.'], 503);
    }

    $provided_code = (string) $request->query->get('code', '');
    if ($provided_code === '' && $request->headers->has('X-Access-Control-Code')) {
      $provided_code = (string) $request->headers->get('X-Access-Control-Code');
    }

    if ($provided_code === '' || !hash_equals($expected_code, $provided_code)) {
      return new JsonResponse(['error' => 'Invalid or missing download code.'], 403);
    }

    try {
      $payload = $this->fallbackStoreCache->getPayload();
    }
    catch (\Throwable $e) {
      \Drupal::logger('access_control_api_logger')->error('Failed to build fallback store: @message', ['@message' => $e->getMessage()]);
      return new JsonResponse(['error' => 'Unable to build fallback export.'], 500);
    }

    $response = new JsonResponse($payload);
    $response->headers->set('Content-Disposition', 'attachment; filename="maker-access-control-store.json"');
    $response->headers->set('Cache-Control', 'no-store, no-cache, must-revalidate');
    return $response;
  }

  /**
   * Logs the access request based on the identifier (UUID, serial, or email).
   */
  protected function logAccessRequest($identifier, $permission_id, $type, Request $request) {
    $config = $this->config('access_control_api_logger.settings');

    $check_user_exists = $config->get('check_user_exists') !== NULL ? (bool) $config->get('check_user_exists') : TRUE;
    $check_user_status = $config->get('check_user_status') !== NULL ? (bool) $config->get('check_user_status') : TRUE;
    // $check_badge_status is now $check_badge_status_explicitly_enabled later in the code.
    // The general $config->get('check_badge_status') is used directly.

    $source = $request->query->get('source', 'unknown');
    // \Drupal::logger('access_control_api_logger')->notice('Source parameter: @source', ['@source' => $source]);

    $method = $request->query->get('method', 'unknown');
    // \Drupal::logger('access_control_api_logger')->notice('Method parameter: @method', ['@method' => $method]);

    $user_supplied_note = $request->query->get('note', '');

    $combine_notes = function ($system_note, $user_note) {
      if (!empty($user_note)) {
        if (!empty($system_note)) {
          return $system_note . '. ' . $user_note;
        }
        return $user_note;
      }
      return $system_note;
    };

    $user = NULL;
    if ($check_user_exists) {
      if ($type === 'uuid') {
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['uuid' => $identifier]);
        $user = reset($users);
        if (!$user) {
          \Drupal::logger('access_control_api_logger')->error('No user found for UUID: @uuid', ['@uuid' => $identifier]);
          $final_note = $combine_notes('No user found.', $user_supplied_note);
          $this->logAccessControlRequest(NULL, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'No matching user found.'], 404);
        }
      }
      elseif ($type === 'serial') {
        $user = $this->getUserBySerial($identifier);
        if (!$user) {
          \Drupal::logger('access_control_api_logger')->error('No user found for Serial: @serial', ['@serial' => $identifier]);
          $final_note = $combine_notes('No user found.', $user_supplied_note);
          $this->logAccessControlRequest(NULL, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'No matching user found.'], 404);
        }
      }
      elseif ($type === 'email') {
        $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['mail' => $identifier]);
        $user = reset($users);
        if (!$user) {
          \Drupal::logger('access_control_api_logger')->error('No user found for Email: @email', ['@email' => $identifier]);
          $final_note = $combine_notes('No user found.', $user_supplied_note);
          $this->logAccessControlRequest(NULL, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'No matching user found.'], 404);
        }
      }
      else {
        \Drupal::logger('access_control_api_logger')->error('Invalid identifier type: @type', ['@type' => $type]);
        $final_note = $combine_notes('Invalid identifier type.', $user_supplied_note);
        // No call to logAccessControlRequest here, as it would be for a non-existent user/badge.
        return new JsonResponse(['error' => 'Invalid identifier type.'], 400);
      }
    }

    if ($check_user_status && $user) {
      $config_settings = $this->config('access_control_api_logger.settings');
      $check_pause_payment_enabled = $config_settings->get('check_pause_payment') ?? TRUE;

      if ($check_pause_payment_enabled) {
        if ($user->hasField('field_chargebee_payment_pause') && $user->get('field_chargebee_payment_pause')->value) {
          $final_note = $combine_notes('User account on Chargebee payment pause.', $user_supplied_note);
          $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'User account on Chargebee payment pause.'], 403);
        }
        if ($user->hasField('field_manual_pause') && $user->get('field_manual_pause')->value) {
          $final_note = $combine_notes('User account on manual pause.', $user_supplied_note);
          $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'User account on manual pause.'], 403);
        }
        if ($user->hasField('field_payment_failed') && $user->get('field_payment_failed')->value) {
          $final_note = $combine_notes('User account has payment failed status.', $user_supplied_note);
          $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
          return new JsonResponse(['error' => 'User account has payment failed status.'], 403);
        }
      }
      if ($user->hasField('field_access_override') && $user->get('field_access_override')->value === 'deny') {
        $final_note = $combine_notes('User access explicitly denied by override.', $user_supplied_note);
        $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
        return new JsonResponse(['error' => 'User access explicitly denied by override.'], 403);
      }
      
      $user_roles = $user->getRoles();
      $allowed_roles = ['member', 'services', 'instructor']; 
      if (empty(array_intersect($allowed_roles, $user_roles))) {
        $final_note = $combine_notes('User does not have valid roles.', $user_supplied_note);
        $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
        return new JsonResponse(['error' => 'User does not have a valid role for access.'], 403);
      }
    }

    // \Drupal::logger('access_control_api_logger')->notice('Checking if permission exists...');
    $badge = NULL;
    $requested_permission = $permission_id ? strtolower(trim($permission_id)) : '';
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'badges']);
    foreach ($terms as $term) {
      $field_value = $term->get('field_badge_text_id')->value;
      if ($field_value && strtolower($field_value) === $requested_permission) {
        $badge = $term;
        break;
      }
    }

    if (!$badge) {
      \Drupal::logger('access_control_api_logger')->error('Invalid permission ID: "@permission_id" requested. User context: @user_id', [
          '@permission_id' => $permission_id,
          '@user_id' => $user ? $user->id() : ($check_user_exists ? 'user_not_found_or_disabled_check' : 'user_check_disabled'),
      ]);
      $final_note = $combine_notes('Invalid permission ID.', $user_supplied_note);
      $this->logAccessControlRequest($user, NULL, FALSE, $final_note, $source, $method);
      return new JsonResponse(['error' => 'Invalid permission ID.'], 400);
    }

    $check_user_has_permission_enabled = $config->get('check_user_has_permission') ?? TRUE;
    if ($check_user_has_permission_enabled && $user && $badge) {
        $query = \Drupal::entityQuery('node')
            ->condition('type', 'badge_request')
            ->condition('field_member_to_badge', $user->id())
            ->condition('field_badge_requested', $badge->id());

        $check_badge_status_explicitly_enabled = $config->get('check_badge_status') ?? TRUE;
        if ($check_badge_status_explicitly_enabled) {
            $query->condition('field_badge_status.value', 'active');
        }
        $query->accessCheck(FALSE);
        $nids = $query->execute();

        if (empty($nids)) {
            $system_note_badge = $check_badge_status_explicitly_enabled ? 'No active badge request found.' : 'User does not have the specified permission.';
            $final_note = $combine_notes($system_note_badge, $user_supplied_note);
            $this->logAccessControlRequest($user, $badge, FALSE, $final_note, $source, $method);
            return new JsonResponse(['error' => $system_note_badge], 403);
        }
    }

    $final_note = $combine_notes('', $user_supplied_note);
    $this->logAccessControlRequest($user, $badge, TRUE, $final_note, $source, $method);

    return new JsonResponse([
      [
        'first_name' => $user ? $user->get('field_first_name')->value : NULL,
        'last_name' => $user ? $user->get('field_last_name')->value : NULL,
        'permission' => strtolower($badge->getName()),
        'access' => 'true',
        'uuid' => $user ? $user->uuid->value : NULL,
        'source' => $source,
        'method' => $method,
      ]
    ]);
  }

  /**
   * Logs access control request in the access_control_log entity.
   */
  protected function logAccessControlRequest($user, $badge, $result, $note = '', $source = 'unknown', $method = 'unknown') {
    $values = [
      'type' => 'access_control_request',
      'field_access_request_user' => $user ? $user->id() : NULL,
      'field_access_request_result' => $result ? 1 : 0,
      'field_access_request_note' => $note,
      'field_access_request_source' => $source,
    ];

    $storage = \Drupal::entityTypeManager()->getStorage('access_control_log');
    $log_entry = $storage->create($values);

    if ($badge && $log_entry->hasField('field_access_request_permission')) {
      $log_entry->set('field_access_request_permission', $badge->id());
    }
    if ($log_entry->hasField('field_access_request_method')) {
      $log_entry->set('field_access_request_method', $method);
    }

    // VERBOSE LOG REMOVED FOR PRODUCTION
    // \Drupal::logger('access_control_api_logger')->notice('Logging access control request: User ID = @uid, Badge = @badge, Result = @result, Note = @note, Source = @source, Method = @method', [
    //   '@uid' => $user ? $user->id() : 'NULL',
    //   '@badge' => $badge ? $badge->id() : 'NULL',
    //   '@result' => $result ? 'Success' : 'Failure',
    //   '@note' => $note,
    //   '@source' => $source,
    //   '@method' => $method,
    // ]);

    $log_entry->save();

    // VERBOSE LOG REMOVED FOR PRODUCTION
    // \Drupal::logger('access_control_api_logger')->notice('Access control log entry saved successfully.');
  }

  /**
   * Helper function to find a user by serial number.
   */
  protected function getUserBySerial($serial) {
    // ... (getUserBySerial method remains the same) ...
    $user_query = \Drupal::entityQuery('user')
      ->condition('field_card_serial_number', $serial)
      ->accessCheck(FALSE);
    $user_ids = $user_query->execute();

    if (!empty($user_ids)) {
      $users = User::loadMultiple($user_ids);
      return reset($users);
    }

    $profile_query = \Drupal::entityQuery('profile')
      ->condition('type', 'main')
      ->condition('field_card_serial_number', $serial)
      ->accessCheck(FALSE);
    $profile_ids = $profile_query->execute();

    if (!empty($profile_ids)) {
      $profiles = \Drupal\profile\Entity\Profile::loadMultiple($profile_ids);
      foreach ($profiles as $profile) {
        $user = $profile->getOwner();
        if ($user) {
          return $user;
        }
      }
    }
    return NULL;
  }

  /**
   * Lists all active permissions.
   */
  public function listAllPermissions() {
    // ... (listAllPermissions method remains the same) ...
    $terms = \Drupal::entityTypeManager()->getStorage('taxonomy_term')->loadByProperties(['vid' => 'badges']);
    if (empty($terms)) {
      return new JsonResponse(['error' => 'No permissions found.'], 404);
    }
    $permissions = [];
    foreach ($terms as $term) {
      $permissions[] = [
        'badge_name' => $term->getName(),
        'permission_id' => $term->get('field_badge_text_id')->value,
      ];
    }
    return new JsonResponse(['permissions' => $permissions]);
  }

  /**
   * Gets user info by serial number.
   */
  public function getUserInfoBySerial($serial) {
    // ... (getUserInfoBySerial method remains the same) ...
    $user = $this->getUserBySerial($serial);
    if (!$user) {
      return new JsonResponse(['error' => 'User not found for serial.'], 404);
    }
    return new JsonResponse([
      'first_name' => $user->get('field_first_name')->value,
      'last_name' => $user->get('field_last_name')->value,
      'uuid' => $user->uuid->value,
      'access' => $user->get('status')->value ? 'Active' : 'Inactive',
    ]);
  }

  /**
   * Gets user info by UUID.
   */
  public function getUserInfoByUuid($uuid) {
    // ... (getUserInfoByUuid method remains the same) ...
    $users = \Drupal::entityTypeManager()->getStorage('user')->loadByProperties(['uuid' => $uuid]);
    $user = reset($users);
    if (!$user) {
      return new JsonResponse(['error' => 'User not found for UUID.'], 404);
    }
    return new JsonResponse([
      'first_name' => $user->get('field_first_name')->value,
      'last_name' => $user->get('field_last_name')->value,
      'uuid' => $user->uuid->value,
      'access' => $user->get('status')->value ? 'Active' : 'Inactive',
    ]);
  }
}
