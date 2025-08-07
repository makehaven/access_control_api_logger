# Access Control API and Logger

## Overview
The Access Control API Logger is a custom Drupal module designed to log access control requests based on either a user's UUID or card serial number. The module supports two types of requests:

- UUID-based Access Requests: Logs access requests based on the user's UUID.
- Serial-based Access Requests: Logs access requests based on the user's card serial number.

It checks if the user has an active badge (permission) and logs the result. Additional features include tracking the source of the access request and handling special access restrictions (e.g., paused membership or payment failures).

## Features
- Access by UUID or Serial: Supports both UUID and serial number for identifying users.
- Permission Check: Verifies if the user has the necessary permission (badge) for access.
- Access Restriction: Denies access if the user has paused payments or has a failed payment status.
- Logging: Logs all access requests, including the result (granted or denied), the permission checked, and additional notes.
- Source Parameter: Optionally logs the source of the access request (e.g., building door reader, web interface, etc.).

## Installation
1. Download and place the module in your Drupal `modules/custom/` directory.
2. Enable the module using Drush or the Drupal admin UI:

   drush en access_control_api_logger

3. Ensure that the `access_control_log` entity has the following fields:
   - `field_access_request_user` (Reference to the user making the request)
   - `field_access_request_permission` (Reference to the permission requested)
   - `field_access_request_result` (Boolean for access granted or denied)
   - `field_access_request_note` (Text for additional information)
   - Optional: `field_access_request_source` (Text field for logging the source of the request)

## Usage

### UUID-based Access Requests
To log an access request using a user's UUID, make a request to the following endpoint:

   /api/v0/uuid/{uuid}/permission/{permission_id}?source={source}

- `uuid`: The UUID of the user.
- `permission_id`: The permission (badge) being requested (case-insensitive).
- `source` (optional): The source of the access request (e.g., `building_door_reader`, `web_interface`, etc.).

### Serial-based Access Requests
To log an access request using a card serial number, make a request to the following endpoint:

   /api/v0/serial/{serial}/permission/{permission_id}?source={source}

- `serial`: The serial number of the user's access card.
- `permission_id`: The permission (badge) being requested (case-insensitive).
- `source` (optional): The source of the access request.

### Email-based Access Requests
To log an access request using a user's email, make a request to the following endpoint:

   /api/v0/email/{email}/permission/{permission_id}?source={source}

- `email`: The email address of the user.
- `permission_id`: The permission (badge) being requested (case-insensitive).
- `source` (optional): The source of the access request (e.g., `building_door_reader`, `web_interface`, etc.).

### Example Requests

**Email-based Request:**

   http://localhost/api/v0/email/user@example.com/permission/door?source=building_door_reader

### Example Requests

**UUID-based Request:**

   http://localhost/api/v0/uuid/83a1e286-a88e-4388-bd58-680f6a7b2f8e/permission/door?source=building_door_reader

**Serial-based Request:**

   http://localhost/api/v0/serial/048983a1672681/permission/laser_cutter?source=computer_terminal

## Access Denial Conditions
The following conditions will result in access denial, with appropriate notes logged:

- **Paused Membership**: If `field_chargebee_payment_pause` on the user's account is `TRUE`, access will be denied with the note "Member paused."
- **Payment Failure**: If `field_payment_failed` on the user's account is `TRUE`, access will be denied with the note "Payment failed."

## Logging Behavior
Each access request is logged in the `access_control_log` entity with the following details:

- **User**: The user making the request.
- **Permission**: The permission (badge) being requested.
- **Result**: Whether access was granted or denied (boolean).
- **Note**: Any additional information or reasons for denial.
- **Source** (optional): The source of the request (if provided in the URL).

## Field Handling
The module checks if the following fields exist on the user entity before performing access checks. If these fields do not exist, the code will continue without breaking:

- `field_chargebee_payment_pause`
- `field_payment_failed`

Additionally, the `field_access_request_source` on the `access_control_log` entity is optional. If it does not exist, the logging will proceed without adding the source to the log.

## Error Handling
- **Invalid UUID/Serial**: If the user is not found by the provided UUID or serial number, access will be denied, and the message "Invalid user UUID/serial" will be logged.
- **Invalid Permission**: If the provided `permission_id` does not match any badge's `field_badge_text_id`, access will be denied, and the message "Invalid permission ID" will be logged.

## Customization

### Adding the Source Field
If you wish to track the source of access requests, add a new text field `field_access_request_source` to the `access_control_log` entity. This field can store the origin of the request, such as `building_door_reader` or `web_interface`.

### Sharing the Module
The module is designed to be shared with other installations. It checks for the existence of fields like `field_chargebee_payment_pause`, `field_payment_failed`, and `field_access_request_source` before using them to avoid errors in environments that do not have these fields.

## License
This module is open-source and licensed under the MIT License.
