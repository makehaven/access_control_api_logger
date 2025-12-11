<?php

use Drupal\user\Entity\User;
use Drupal\profile\Entity\Profile;

/**
 * Script to migrate card serial numbers from Profile to User entities.
 *
 * Usage:
 *   lando drush scr modules/custom/access_control_api_logger/scripts/migrate_card_serials.php
 */

echo "Starting card serial migration...\n";

// Parse arguments for --min-uid
$min_uid = 0;
if (isset($argv)) {
  foreach ($argv as $arg) {
    if (strpos($arg, '--min-uid=') === 0) {
      $min_uid = (int) substr($arg, strlen('--min-uid='));
      echo "Resuming from User ID > $min_uid\n";
    }
  }
}

// Build query to find 'main' profiles.
$query = \Drupal::entityQuery('profile')
  ->condition('type', 'main')
  ->accessCheck(FALSE);

// Apply min-uid filter if set.
if ($min_uid > 0) {
  $query->condition('uid', $min_uid, '>');
}

// Sort by uid to ensure deterministic order and resuming.
$query->sort('uid', 'ASC');

$ids = $query->execute();

if (empty($ids)) {
  echo "No 'main' profiles found matching criteria.\n";
  exit;
}

$count_ids = count($ids);
echo "Found $count_ids profiles to process.\n";

$updated_count = 0;
$skipped_count = 0;

// Process in chunks to save memory.
$chunks = array_chunk($ids, 50);

foreach ($chunks as $chunk_ids) {
  $profiles = Profile::loadMultiple($chunk_ids);

  foreach ($profiles as $profile) {
    $user = $profile->getOwner();
    if (!$user) {
      continue;
    }

    $changed = FALSE;
    $fields_to_migrate = ['field_card_serial_number', 'field_card_serial_retired'];

    foreach ($fields_to_migrate as $field_name) {
      // Check if both entities have the field definition.
      if (!$profile->hasField($field_name) || !$user->hasField($field_name)) {
        continue;
      }

      $profile_values = $profile->get($field_name)->getValue();
      $user_values = $user->get($field_name)->getValue();
      
      // Extract raw string values.
      $p_vals = array_column($profile_values, 'value');
      $u_vals = array_column($user_values, 'value');

      // Filter out empty strings.
      $p_vals = array_filter($p_vals, 'strlen');
      $u_vals = array_filter($u_vals, 'strlen');

      if (empty($p_vals)) {
        continue;
      }

      // Merge profile values into user values.
      $merged_vals = array_merge($u_vals, $p_vals);
      // Remove duplicates.
      $unique_vals = array_unique($merged_vals);
      
      // Sort for comparison.
      sort($u_vals);
      sort($unique_vals);
      
      // If the resulting set is different from what the user already had, update.
      if ($u_vals !== $unique_vals) {
         $final_values = [];
         foreach ($unique_vals as $val) {
           $final_values[] = ['value' => $val];
         }
         $user->set($field_name, $final_values);
         $changed = TRUE;
         echo "User " . $user->id() . " (" . $user->getAccountName() . "): Migrating $field_name: " . implode(', ', $p_vals) . "\n";
      }
    }

    if ($changed) {
      try {
        $user->save();
        $updated_count++;
        echo "User " . $user->id() . ": Saved successfully.\n";
      }
      catch (\Exception $e) {
        echo "ERROR saving User " . $user->id() . ": " . $e->getMessage() . "\n";
      }
    } else {
      $skipped_count++;
    }
  }
  
  // Clear storage cache after each chunk to free memory.
  \Drupal::entityTypeManager()->getStorage('user')->resetCache();
  \Drupal::entityTypeManager()->getStorage('profile')->resetCache();
}

echo "Migration complete.\n";
echo "Updated Users: $updated_count\n";
echo "Skipped/Up-to-date Users: $skipped_count\n";
