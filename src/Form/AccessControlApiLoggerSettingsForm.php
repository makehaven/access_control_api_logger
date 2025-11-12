<?php

namespace Drupal\access_control_api_logger\Form;

use Drupal\Core\Form\ConfigFormBase;
use Drupal\Core\Form\FormStateInterface;

/**
 * Configure settings for Access Control API Logger.
 */
class AccessControlApiLoggerSettingsForm extends ConfigFormBase {

  /**
   * {@inheritdoc}
   */
  protected function getEditableConfigNames() {
    return [
      'access_control_api_logger.settings',
    ];
  }

  /**
   * {@inheritdoc}
   */
  public function getFormId() {
    return 'access_control_api_logger_settings_form';
  }

  /**
   * {@inheritdoc}
   */
  public function buildForm(array $form, FormStateInterface $form_state) {
    $config = $this->config('access_control_api_logger.settings');

    // Documentation: Logic Description for Administrators.
    $form['logic_documentation'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Access Control Logic Documentation'),
      '#collapsible' => TRUE,
      '#collapsed' => FALSE,
      '#description' => $this->t('This section describes the access control logic used for evaluating user permissions. It can be used as a reference for understanding the logic flow.'),
    ];

    $form['logic_documentation']['logic_description'] = [
      '#type' => 'markup',
      '#markup' => '<div style="background: #f9f9f9; padding: 15px; border: 1px solid #ccc; border-radius: 4px;">
        <strong>Access Control Logic Flow:</strong><br><br>
        <ol>
          <li><strong>Step 1: Check if User Exists (check_user_exists)</strong><br>
          - If enabled, the system checks if the user exists using UUID or Serial.<br>
          - If not found, access is denied.<br>
          - If disabled, a default user is used.</li><br>
          
          <li><strong>Step 2: Check User Status (check_user_status)</strong><br>
          - If enabled, ensure the user is an active member.<br>
          - If the user has "field_chargebee_payment_pause", "field_manual_pause", or "field_payment_failed" set to TRUE, access is denied.<br>
          - If the user has "deny" in "field_access_override", access is denied.</li><br>
          
          <li><strong>Step 3: Always Check if Permission Exists</strong><br>
          - Check if the requested permission exists in the badges vocabulary.<br>
          - If not found, access is denied.</li><br>
          
          <li><strong>Step 4: Check User Has Permission (check_user_has_permission)</strong><br>
          - If enabled, verify the user has an active badge request for the permission.<br>
          - If the badge request is not active, access is denied.</li><br>
          
          <li><strong>Step 5: Check Badge Status (check_badge_status)</strong><br>
          - If enabled, ensure the badge status is active or blank.<br>
          - If disabled, any badge status is treated as valid.</li><br>
          
          <li><strong>Final Step:</strong><br>
          - If all checks pass, access is granted.<br>
          - If any check fails, access is denied with an appropriate message.</li>
        </ol>
      </div>',
    ];

    // Logic overview and options section.
    $form['logic_overview'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Access Control Logic Checks'),
      '#description' => $this->t('Control which conditions are evaluated for access checks.'),
    ];

    $form['logic_overview']['check_user_exists'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check if user exists (UUID/Serial)'),
      '#default_value' => $config->get('check_user_exists') ?? TRUE,
      '#description' => $this->t('Ensure the user exists for the provided UUID or Serial ID. If unchecked, a default user will be used.'),
    ];

    $form['logic_overview']['check_user_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check if user is an active member or other approved role'),
      '#default_value' => $config->get('check_user_status') ?? TRUE,
      '#description' => $this->t('Ensure the user is an active member and not suspended.'),
    ];

    // Add the new checkbox for the pause/payment check.
    $form['logic_overview']['check_pause_payment'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check pause/payment fields (Chargebee Pause, Manual Pause, Payment Failed)'),
      '#default_value' => $config->get('check_pause_payment') ?? TRUE,
      '#description' => $this->t('Ensure none of the following fields are true: field_chargebee_payment_pause, field_manual_pause, field_payment_failed.'),
    ];

    $form['logic_overview']['check_user_has_permission'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check if user has the specified permission'),
      '#default_value' => $config->get('check_user_has_permission') ?? TRUE,
      '#description' => $this->t('Ensure the user has the requested permission badge.'),
    ];

    $form['logic_overview']['check_badge_status'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Check badge status (active/blank)'),
      '#default_value' => $config->get('check_badge_status') ?? TRUE,
      '#description' => $this->t('Ensure the badge is either active or not restricted. If unchecked, any status is treated as valid.'),
    ];

    $form['fallback_export'] = [
      '#type' => 'fieldset',
      '#title' => $this->t('Fallback JSON export'),
      '#description' => $this->t('Configure how the lightweight Maker Access Control UI downloads the combined users/tools/assignments JSON file.'),
    ];

    $form['fallback_export']['fallback_shared_code'] = [
      '#type' => 'textfield',
      '#title' => $this->t('Shared download code'),
      '#default_value' => $config->get('fallback_shared_code') ?? '',
      '#description' => $this->t('Provide this code to the fallback system so it can call /api/v0/access-control/fallback-store?code=YOURCODE (or send it via the X-Access-Control-Code header). Leave blank to disable the export entirely.'),
      '#attributes' => ['autocomplete' => 'off'],
    ];

    $form['fallback_export']['fallback_cache_enabled'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Cache the generated JSON payload'),
      '#default_value' => $config->get('fallback_cache_enabled') ?? TRUE,
      '#description' => $this->t('When enabled, the export payload is cached and reused until it expires or is invalidated by cron/entity changes.'),
    ];

    $form['fallback_export']['fallback_cache_max_age'] = [
      '#type' => 'number',
      '#title' => $this->t('Cache lifetime (seconds)'),
      '#default_value' => $config->get('fallback_cache_max_age') ?? 900,
      '#min' => 60,
      '#step' => 60,
      '#description' => $this->t('How long the cached JSON should be reused before being rebuilt.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_cache_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['fallback_export']['fallback_cache_refresh_cron'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Refresh cache during cron'),
      '#default_value' => $config->get('fallback_cache_refresh_cron') ?? TRUE,
      '#description' => $this->t('If checked, cron will rebuild the cached JSON proactively so downloads stay fast.'),
      '#states' => [
        'visible' => [
          ':input[name="fallback_cache_enabled"]' => ['checked' => TRUE],
        ],
      ],
    ];

    $form['fallback_export']['fallback_limit_permissions'] = [
      '#type' => 'textarea',
      '#title' => $this->t('Limit to permission IDs'),
      '#default_value' => $config->get('fallback_limit_permissions') ?? '',
      '#description' => $this->t('Optional newline or comma-delimited list of badge permission IDs (field_badge_text_id values). Leave blank to include every badge.'),
    ];

    $form['fallback_export']['fallback_include_user_names'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include first/last name in fallback export'),
      '#default_value' => $config->get('fallback_include_user_names') ?? FALSE,
      '#description' => $this->t('When checked, the fallback JSON will include user first_name and last_name values. Keep disabled to omit them for security.'),
    ];

    $form['fallback_export']['fallback_include_user_email'] = [
      '#type' => 'checkbox',
      '#title' => $this->t('Include email in fallback export'),
      '#default_value' => $config->get('fallback_include_user_email') ?? FALSE,
      '#description' => $this->t('When checked, the fallback JSON will include the user email field. Leave unchecked to exclude email addresses.'),
    ];

    return parent::buildForm($form, $form_state);
  }

  /**
   * {@inheritdoc}
   */
  public function submitForm(array &$form, FormStateInterface $form_state) {
    $this->config('access_control_api_logger.settings')
      ->set('check_user_exists', $form_state->getValue('check_user_exists'))
      ->set('check_user_status', $form_state->getValue('check_user_status'))
      ->set('check_pause_payment', $form_state->getValue('check_pause_payment')) // Store the pause/payment setting.
      ->set('check_user_has_permission', $form_state->getValue('check_user_has_permission'))
      ->set('check_badge_status', $form_state->getValue('check_badge_status'))
      ->set('fallback_shared_code', trim((string) $form_state->getValue('fallback_shared_code')))
      ->set('fallback_cache_enabled', (bool) $form_state->getValue('fallback_cache_enabled'))
      ->set('fallback_cache_max_age', (int) $form_state->getValue('fallback_cache_max_age'))
      ->set('fallback_cache_refresh_cron', (bool) $form_state->getValue('fallback_cache_refresh_cron'))
      ->set('fallback_limit_permissions', trim((string) $form_state->getValue('fallback_limit_permissions')))
      ->set('fallback_include_user_names', (bool) $form_state->getValue('fallback_include_user_names'))
      ->set('fallback_include_user_email', (bool) $form_state->getValue('fallback_include_user_email'))
      ->save();

    parent::submitForm($form, $form_state);
  }
}
