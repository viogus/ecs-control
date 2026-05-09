# index.php Router Refactor Design

Date: 2026-05-09

## Goal

Refactor `index.php` so it is a small web bootstrap file instead of a long action dispatcher, while preserving the existing `index.php?action=xxx` API contract, session behavior, CSRF behavior, response payloads, and HTTP status codes.

This is a structural refactor only. It must not introduce a PHP framework, route path changes, frontend build tooling, namespaces, database changes, or changes to Alibaba Cloud behavior.

## Current Problem

`index.php` currently handles these responsibilities in one 447-line file:

- PHP session and error setup.
- `AliyunTrafficCheck` construction.
- Public action handling.
- Login/auth gates.
- CSRF token creation and validation.
- JSON response headers and payload writing.
- Action-specific request body parsing.
- Delegation into `AliyunTrafficCheck`.
- Static brand logo serving.

The long flat if/else chain makes it easy to miss cross-cutting rules, especially when adding new mutating actions that need CSRF protection.

## Recommended Approach

Add a small router class under `src/`, tentatively `HttpRouter`, and leave `index.php` as the single web entry point.

`index.php` should remain responsible for process-level setup:

- Configure session cookie settings.
- Start the session.
- Configure error reporting and cache headers.
- Require the application entry class.
- Instantiate `AliyunTrafficCheck`.
- Read the `action` query parameter.
- Call the router.

`HttpRouter` should own request dispatch:

- Define the public action set.
- Define the mutating action set.
- Ensure and validate CSRF tokens.
- Enforce authentication for non-public, non-`view` actions.
- Read JSON request bodies.
- Emit JSON, image, template, and error responses.
- Delegate business work to `AliyunTrafficCheck`.

## Action Coverage

The refactor must preserve all currently supported actions:

- Public/init/auth: `check_init`, `setup`, `login`, `check_login`, `brand_logo`, `get_status`.
- Config and logs: `get_config`, `save_config`, `upload_logo`, `get_logs`, `clear_logs`.
- Notifications: `send_test_email`, `send_test_telegram`, `send_test_webhook`.
- Accounts and instances: `refresh_account`, `fetch_instances`, `test_account`, `get_account_history`, `sync_account_group`, `restore_schedule_block`, `get_all_instances`.
- ECS create flow: `preview_ecs_create`, `get_ecs_disk_options`, `create_ecs`, `get_ecs_create_task`.
- Instance actions: `control_instance`, `delete_instance`, `replace_instance_ip`.
- Session/export/view: `logout`, `export`, `view`.

Unknown actions should continue to fall through to the same behavior currently provided by `index.php`.

## CSRF Design

The CSRF token remains stored at `$_SESSION['csrf_token']`.

The mutating action list should be moved from `index.php` into the router without changing its contents:

- `save_config`
- `upload_logo`
- `send_test_email`
- `send_test_telegram`
- `send_test_webhook`
- `refresh_account`
- `fetch_instances`
- `test_account`
- `sync_account_group`
- `restore_schedule_block`
- `preview_ecs_create`
- `get_ecs_disk_options`
- `create_ecs`
- `clear_logs`
- `control_instance`
- `delete_instance`
- `replace_instance_ip`
- `logout`
- `export`
- `get_all_instances`

Mutating actions must continue to require the `X-CSRF-Token` header. CSRF failures must return HTTP 403 and the same JSON error message currently used by `index.php`.

## Authentication Design

The router should preserve the current gate:

- `view` is allowed without login.
- Public actions are allowed without login.
- Every other action requires `$_SESSION['is_admin']` to be set.

The `get_status` action keeps its current special behavior: it is callable before the generic auth gate, but returns HTTP 403 with the existing JSON error if the user is not logged in.

## Response Design

The router may add helper methods for repeated response patterns:

- `json(array $payload, int $status = 200, int $flags = 0): void`
- `readJsonBody(): array`
- `ensureCsrfToken(): void`
- `requireCsrf(): void`
- `isLoggedIn(): bool`

These helpers must preserve output behavior. In particular:

- `export` continues to use `JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT`.
- `get_status` and `export` keep `charset=utf-8`.
- `brand_logo` keeps existing file lookup, MIME handling, cache header, and 404 behavior.
- `view` continues to render `AliyunTrafficCheck::renderTemplate()`.

## Error Handling

Existing action-level try/catch behavior should be retained. The refactor should avoid adding a broad router-level catch that changes status codes or payload shapes.

Where current code returns HTTP 400 for action errors, it should continue to do so. Where current code returns success false with HTTP 200, it should continue to do so.

## Testing And Verification

Verification should focus on behavior preservation:

- Run PHP syntax checks for `index.php`, the new router file, and changed PHP files.
- Run a quick static scan to confirm every existing action string is still handled.
- If Docker is available, build/start the app and smoke-test representative actions:
  - `check_init`
  - `check_login`
  - unauthenticated protected action returns 403
  - `view` renders HTML
- Avoid relying on live Alibaba Cloud credentials for this refactor.

## Out Of Scope

- Changing frontend request paths or payloads.
- Splitting `AliyunTrafficCheck`.
- Splitting `template.html`.
- Replacing the flat action query API with path-based routing.
- Adding Composer packages or a framework.
- Changing the database schema.
- Changing notification, ECS, CDT, DDNS, or Telegram business behavior.

