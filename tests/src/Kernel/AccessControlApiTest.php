<?php

namespace Drupal\Tests\access_control_api_logger\Kernel;

use Drupal\KernelTests\KernelTestBase;
use Drupal\user\Entity\User;
use Drupal\taxonomy\Entity\Vocabulary;
use Drupal\taxonomy\Entity\Term;
use Drupal\node\Entity\NodeType;
use Drupal\node\Entity\Node;
use Drupal\field\Entity\FieldStorageConfig;
use Drupal\field\Entity\FieldConfig;
use Symfony\Component\HttpFoundation\Request;

/**
 * Tests Access Control API functionality.
 *
 * @group access_control_api_logger
 */
class AccessControlApiTest extends KernelTestBase {

  /**
   * {@inheritdoc}
   */
  protected static $modules = [
    'system',
    'user',
    'taxonomy',
    'node',
    'field',
    'text',
    'options',
    'eck',
    'access_control_api_logger',
  ];

  /**
   * The access status evaluator service.
   *
   * @var \Drupal\access_control_api_logger\Service\AccessStatusEvaluator
   */
  protected $evaluator;

  /**
   * {@inheritdoc}
   */
  protected function setUp(): void {
    parent::setUp();

    $this->installEntitySchema('user');
    $this->installEntitySchema('node');
    $this->installEntitySchema('taxonomy_term');
    $this->installEntitySchema('eck_entity_type');
    $this->installEntitySchema('eck_entity');
    
    $this->installConfig(['system', 'field', 'user', 'node', 'taxonomy', 'access_control_api_logger']);

    // Create badges vocabulary.
    Vocabulary::create([
      'vid' => 'badges',
      'name' => 'Badges',
    ])->save();

    // Create field_badge_text_id on taxonomy terms.
    FieldStorageConfig::create([
      'field_name' => 'field_badge_text_id',
      'entity_type' => 'taxonomy_term',
      'type' => 'string',
    ])->save();
    FieldConfig::create([
      'field_name' => 'field_badge_text_id',
      'entity_type' => 'taxonomy_term',
      'bundle' => 'badges',
      'label' => 'Badge Text ID',
    ])->save();

    // Create badge_request node type.
    NodeType::create([
      'type' => 'badge_request',
      'name' => 'Badge Request',
    ])->save();

    // Create fields for badge_request.
    $fields = [
      'field_member_to_badge' => 'entity_reference',
      'field_badge_requested' => 'entity_reference',
      'field_badge_status' => 'list_string',
    ];
    foreach ($fields as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'type' => $type,
        'settings' => $type === 'entity_reference' ? ['target_type' => ($name === 'field_badge_requested' ? 'taxonomy_term' : 'user')] : [],
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'node',
        'bundle' => 'badge_request',
        'label' => $name,
      ])->save();
    }

    // Create user fields.
    $user_fields = [
      'field_card_serial_number' => 'string',
      'field_chargebee_payment_pause' => 'boolean',
      'field_manual_pause' => 'boolean',
      'field_payment_failed' => 'boolean',
      'field_access_override' => 'list_string',
      'field_first_name' => 'string',
      'field_last_name' => 'string',
    ];
    foreach ($user_fields as $name => $type) {
      FieldStorageConfig::create([
        'field_name' => $name,
        'entity_type' => 'user',
        'type' => $type,
      ])->save();
      FieldConfig::create([
        'field_name' => $name,
        'entity_type' => 'user',
        'bundle' => 'user',
        'label' => $name,
      ])->save();
    }

    $this->evaluator = $this->container->get('access_control_api_logger.access_status_evaluator');
  }

  /**
   * Tests the access evaluator service.
   */
  public function testAccessEvaluation() {
    // 1. Create a "door" badge.
    $door_badge = Term::create([
      'vid' => 'badges',
      'name' => 'Door',
      'field_badge_text_id' => 'door',
    ]);
    $door_badge->save();

    // 2. Create a user with valid role but no badge.
    $user = User::create([
      'name' => 'testuser',
      'mail' => 'test@example.com',
      'roles' => ['member'],
      'status' => 1,
    ]);
    $user->save();

    $result = $this->evaluator->evaluate($user);
    $this->assertEquals('blocked', $result['summary']['state']);
    $this->assertContains('Door badge missing or inactive.', $result['summary']['blocking_messages']);

    // 3. Give user the badge.
    $request = Node::create([
      'type' => 'badge_request',
      'title' => 'Request for Door',
      'field_member_to_badge' => $user->id(),
      'field_badge_requested' => $door_badge->id(),
      'field_badge_status' => 'active',
    ]);
    $request->save();

    $result = $this->evaluator->evaluate($user);
    $this->assertEquals('ok', $result['summary']['state']);

    // 4. Pause the user.
    $user->set('field_manual_pause', 1)->save();
    $result = $this->evaluator->evaluate($user);
    $this->assertEquals('blocked', $result['summary']['state']);
    $this->assertContains('Manual pause prevents access.', $result['summary']['blocking_messages']);
  }

  /**
   * Tests the controller logic via handleSerialRequest.
   */
  public function testControllerSerialRequest() {
    // Setup.
    $door_badge = Term::create([
      'vid' => 'badges',
      'name' => 'Door',
      'field_badge_text_id' => 'door',
    ]);
    $door_badge->save();

    $user = User::create([
      'name' => 'serialuser',
      'mail' => 'serial@example.com',
      'roles' => ['member'],
      'status' => 1,
      'field_card_serial_number' => '12345',
      'field_first_name' => 'John',
      'field_last_name' => 'Doe',
    ]);
    $user->save();

    Node::create([
      'type' => 'badge_request',
      'field_member_to_badge' => $user->id(),
      'field_badge_requested' => $door_badge->id(),
      'field_badge_status' => 'active',
    ])->save();

    $controller = \Drupal\service('class_resolver')->getInstanceFromDefinition('\Drupal\access_control_api_logger\Controller\AccessControlApiLoggerController');
    
    // Test successful access.
    $request = new Request(['source' => 'test_source']);
    $response = $controller->handleSerialRequest('12345', 'door', $request);
    $data = json_decode($response->getContent(), TRUE);

    $this->assertEquals(200, $response->getStatusCode());
    $this->assertEquals('true', $data[0]['access']);
    $this->assertEquals('John', $data[0]['first_name']);

    // Test denied access (invalid serial).
    $response = $controller->handleSerialRequest('99999', 'door', $request);
    $this->assertEquals(404, $response->getStatusCode());
  }

}
