# Account Group Operation Service Refactor Design

Date: 2026-05-10

## Goal

Extract account-group operation orchestration from `AliyunTrafficCheck` into a focused service while preserving the current web API contract, router behavior, logs, synchronization flow, billing refresh behavior, DDNS reconciliation, and error messages.

This is a structural refactor only. It must not change frontend request payloads, route names, database schema, `AccountSyncService`, `InstanceActionService`, or Alibaba Cloud SDK behavior.

## Current Problem

`AliyunTrafficCheck` is now mostly a facade, but it still owns a cohesive account-group operations block:

- Fetching ECS instances for provided AK credentials.
- Testing account credentials and region access.
- Testing CDT traffic access during account validation.
- Resolving masked secrets from the database.
- Syncing a single account group and refreshing instance snapshots.
- Refreshing billing metrics when billing is enabled.
- Reconciling DDNS after account sync.
- Restoring schedule blocks caused by traffic protection.
- Summarizing traffic-sync issues for frontend messages.

These responsibilities are not general facade concerns. They belong in a service that coordinates account group operations while delegating persistence, cloud API calls, frontend snapshot building, and DDNS work to existing collaborators.

## Recommended Approach

Add a new global class `AccountGroupOperationService` under `src/`, with no namespace, following the existing classmap/autoload pattern.

`AliyunTrafficCheck` remains the public facade consumed by `HttpRouter`. It keeps these public methods and their current initialization-error behavior:

- `fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')`
- `testAccountCredentials($account)`
- `syncAccountGroup($groupKey): array`
- `restoreScheduleAfterTrafficBlock($groupKey)`

When initialized successfully, those methods delegate to `AccountGroupOperationService`.

## Service Boundary

`AccountGroupOperationService` owns the account-group operation workflow and receives dependencies through its constructor:

```php
new AccountGroupOperationService(
    $db,
    $configManager,
    $aliyunService,
    $responseBuilder,
    $ddnsService
)
```

It should contain these public methods:

- `fetchInstances($accessKeyId, $accessKeySecret, $regionId = '')`
- `testAccountCredentials($account)`
- `syncAccountGroup($groupKey): array`
- `restoreScheduleAfterTrafficBlock($groupKey)`

It should contain these private helpers:

- `resolveSecretFromDatabase($accessKeyId, $regionId, $groupKey = '')`
- `summarizeTrafficIssueForAccounts(array $accounts)`

## Behavior Preservation

The refactor must preserve all current behavior:

- `fetchInstances()` still rejects missing AK ID or AK Secret with `请先填写AK ID和AK Secret`.
- `fetchInstances()` still passes `null` region to `AliyunService::getInstances()` when region is empty.
- Successful instance fetch still logs `实例列表获取成功 [...] 共 N 台`.
- Client exceptions from instance fetch still map to `阿里云鉴权失败，请检查AK权限或密钥是否正确`.
- Server exceptions from instance fetch still map to `阿里云接口错误 [CODE]: MESSAGE`.
- Generic instance fetch failures still map to `实例列表获取失败: 网络或系统错误`.
- `testAccountCredentials()` still requires AK ID, AK Secret, region, and max traffic fields with the same validation message.
- Masked `AccessKeySecret` (`********`) still resolves from stored account groups or accounts using the same fallback behavior.
- Account test still checks region access through `getRegions()`.
- Account test still counts instances in the selected region.
- Account test still probes CDT traffic access and returns warning payload fields when CDT fails.
- Account test success still returns the current payload shape: `success`, `message`, `monitorWarning`, `monitorStatus`, `monitorMessage`, `usageUsed`, `usageRemaining`, `usagePercent`, and `instanceCount`.
- Account test client/server/generic exceptions still log and rethrow with the current messages.
- `syncAccountGroup()` still rejects missing group key and unknown group with current messages.
- `syncAccountGroup()` still calls `syncAccountGroups(true)` for the full configured set.
- `syncAccountGroup()` still refreshes instance snapshots only for accounts in the clicked group that have `instance_id`.
- Billing metrics still refresh only when billing is enabled.
- DDNS reconciliation still receives the before/after account lists and reason `账号同步`.
- Sync success logs keep `账号同步完成 [remark] region 实例 N 台`.
- Sync response still includes `success`, `message`, `instanceCount`, and `trafficIssue`.
- Traffic issue text remains:
  - `部分账号 CDT 鉴权失败，请检查 AK 权限配置`
  - `部分账号 CDT 请求超时，请稍后重试`
  - `部分账号流量同步失败，请稍后重试`
- `restoreScheduleAfterTrafficBlock()` still validates group key, finds the group, calls `ConfigManager::restoreScheduleAfterTrafficBlock()`, logs the restore action, and returns the same success message.

## Relationship To Existing Services

`AccountSyncService` remains responsible for reconciling configured account groups into the `accounts` table. This refactor does not move or alter that lower-level persistence sync.

`InstanceActionService` remains responsible for individual managed instance refresh/control/delete/IP replacement actions. This refactor does not move `refreshAccount()`, `getAllManagedInstances()`, or instance-control methods.

`AccountGroupOperationService` sits above those lower-level services as a workflow coordinator for router-facing account-group operations.

## Router And API Flow

`HttpRouter` stays unchanged.

The router continues to catch exceptions and return the same HTTP 400 JSON payloads for:

- `fetch_instances`
- `test_account`
- `sync_account_group`
- `restore_schedule_block`

This refactor changes only the internal destination of the facade methods.

## Error Handling

`AliyunTrafficCheck` keeps its current initialization guard:

- `fetchInstances()`, `testAccountCredentials()`, `syncAccountGroup()`, and `restoreScheduleAfterTrafficBlock()` throw the init error when initialization failed.

`AccountGroupOperationService` should not know about `initError`. It assumes dependencies are valid.

## Testing And Verification

Add `tests/AccountGroupOperationServiceTest.php` as a dependency-free PHP test runner using fake dependencies.

Coverage should include:

- `fetchInstances()` success returns provider instances and writes the success log.
- `fetchInstances()` rejects missing credentials with the existing validation message.
- `testAccountCredentials()` success returns current payload shape, usage calculations, and instance count.
- `testAccountCredentials()` returns CDT warning fields when the CDT probe fails.
- `testAccountCredentials()` resolves masked secrets from stored data.
- `syncAccountGroup()` success calls full sync, reloads config, refreshes only matching instances, refreshes billing metrics when enabled, reconciles DDNS, writes the success log, and returns current response shape.
- `syncAccountGroup()` includes traffic issue text for `auth_error`, `timeout`, and generic failures.
- `restoreScheduleAfterTrafficBlock()` success calls config restore, logs, and returns the current success message.
- Unknown account groups throw the current `账号组不存在，请刷新页面后重试` message.

Run verification with Docker PHP because local `php` may not exist:

- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AccountGroupOperationServiceTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/EcsCreateServiceTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/HttpRouterTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php tests/AdminSupportServiceTest.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l AliyunTrafficCheck.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l src/AccountGroupOperationService.php`
- `docker run --rm -v /Users/cdf/Codes/ecs-control:/app -w /app php:8.2-cli php -l tests/AccountGroupOperationServiceTest.php`

## Out Of Scope

- Changing `HttpRouter`.
- Changing frontend request payloads or UI behavior.
- Changing `AccountSyncService` internals.
- Changing `InstanceActionService` internals.
- Moving `refreshAccount()` or `getAllManagedInstances()`.
- Changing `ConfigManager` schema, normalization, or encryption behavior.
- Changing Alibaba Cloud SDK calls in `AliyunService`.
- Introducing namespaces, frameworks, Composer packages, PHPUnit, or a build tool.

