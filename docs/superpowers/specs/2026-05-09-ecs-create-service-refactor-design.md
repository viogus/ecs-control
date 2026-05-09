# ECS Create Service Refactor Design

Date: 2026-05-09

## Goal

Extract ECS creation orchestration from `AliyunTrafficCheck` into a focused service while preserving the current web API contract, router behavior, task tracking, logs, DDNS sync, account sync, and notification behavior.

This is a structural refactor only. It must not change the ECS creation request format, preview/session flow, Alibaba Cloud API calls, database schema, frontend code, or route names.

## Current Problem

`AliyunTrafficCheck` still owns a large ECS creation workflow after the router and admin-support extractions. The class currently mixes facade responsibilities with these creation-specific concerns:

- Resolving an account group into a temporary account payload.
- Detecting the client public IP for preview generation.
- Building ECS create previews.
- Querying system disk options.
- Creating ECS tasks and updating progress.
- Calling `EcsProvisionService` to create the instance.
- Syncing account groups after creation.
- Updating EIP metadata for newly created accounts.
- Triggering DDNS reconciliation.
- Logging success/failure.
- Sending ECS creation notifications.

This makes `AliyunTrafficCheck` harder to reason about and keeps a cohesive workflow embedded inside a general application facade.

## Recommended Approach

Add a new global class `EcsCreateService` under `src/`, with no namespace, following the existing classmap/autoload pattern.

`AliyunTrafficCheck` remains the facade consumed by `HttpRouter`. It keeps the existing public method names and their initialization-error behavior:

- `previewEcsCreate($data): array`
- `getEcsDiskOptions($data)`
- `createEcsFromPreview($previewId, array $preview): array`
- `getEcsCreateTask($taskId): ?array`

When initialized successfully, those methods delegate to `EcsCreateService`.

## Service Boundary

`EcsCreateService` owns the ECS create workflow and receives dependencies through its constructor:

```php
new EcsCreateService(
    $db,
    $configManager,
    $ecsProvisionService,
    $ddnsService,
    $notificationService
)
```

It should contain these public methods:

- `previewEcsCreate($data): array`
- `getEcsDiskOptions($data)`
- `createEcsFromPreview($previewId, array $preview): array`
- `getEcsCreateTask($taskId): ?array`

It should contain these private helpers:

- `resolveAccountGroupForCreate($groupKey, $regionId = '')`
- `detectClientPublicIp()`

## Behavior Preservation

The refactor must preserve all current behavior:

- Missing account group in preview or disk-options requests throws `请选择用于创建 ECS 的账号`.
- Unknown account group throws `未找到对应账号，请先在账号管理中保存账号`.
- Missing preview account group throws `创建预检已失效，请重新预检`.
- Preview IDs keep the `preview_` prefix and 12 random bytes.
- Create task IDs keep the `ecs_` prefix and 10 random bytes.
- Preview success logs keep the current message shape.
- Creating ECS still calls `blockCurrentlyStoppedInstances()` before task creation.
- Task creation still stores preview ID, group key, region ID, instance type, and payload.
- Progress callbacks still update the task `step`.
- Success updates still store all current task fields and set `status` to `success`.
- EIP metadata update still happens only for newly created accounts where `publicIpMode` is `eip`.
- Account groups still sync and reload after successful creation.
- DDNS still syncs all accounts after creation with reason `ECS 创建后`.
- Success and failure logs keep their current message shapes.
- Notifications still call `notifyEcsCreated()` and log the notification result through `Helpers::logNotificationResult()`.
- Failure still updates task status to `failed`, sets step `创建失败`, stores stripped error text, writes an error log, and rethrows the exception.
- `getEcsCreateTask()` still returns the database task record directly.

## Router And Session Flow

`HttpRouter` stays unchanged.

The router continues to:

- Store preview summaries in `$_SESSION['ecs_create_previews']`.
- Enforce the 900-second preview expiration.
- Require `confirmed` before creation.
- Remove the preview from session after successful creation.
- Mask `login_password` when returning task details.

This refactor intentionally leaves that request/session boundary in the router because it is HTTP-specific state, not ECS creation domain logic.

## Error Handling

`AliyunTrafficCheck` keeps its current initialization guard:

- `previewEcsCreate()`, `getEcsDiskOptions()`, and `createEcsFromPreview()` throw the init error when initialization failed.
- `getEcsCreateTask()` returns `null` when initialization failed.

`EcsCreateService` should not know about `initError`. It assumes dependencies are valid.

## Testing And Verification

Add `tests/EcsCreateServiceTest.php` as a dependency-free PHP test runner using fake dependencies.

Coverage should include:

- Preview success returns the current payload shape and writes the preview-complete log.
- Missing account group throws the existing validation message.
- Disk options resolves the selected account and wraps provider data with `success => true`.
- Successful creation creates a task, records progress, updates task success fields, syncs account groups, reloads config, updates EIP metadata when needed, syncs DDNS, writes the success log, sends notification, and returns the current success payload.
- Failed creation marks the task failed, stores the stripped error message, writes the failure log, and rethrows.
- `getEcsCreateTask()` delegates to the database.

Run verification with Docker PHP because local `php` may not exist:

- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/EcsCreateService.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/EcsCreateServiceTest.php`

## Out Of Scope

- Changing `HttpRouter`.
- Changing frontend request payloads or UI behavior.
- Moving preview session storage out of the router.
- Changing `EcsProvisionService` internals.
- Changing `AliyunService`.
- Changing account sync behavior outside the existing successful-create path.
- Changing database schema.
- Introducing namespaces, frameworks, Composer packages, PHPUnit, or a build tool.

