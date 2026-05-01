# Code Quality Review — ecs-control

**Date**: 2026-05-02
**Scope**: Full codebase — 14 PHP source files, Nginx config, Docker setup, frontend template
**Methodology**: Manual review of all source code for architecture, duplication, error handling, naming, type safety, maintainability, and adherence to project conventions

---

## Executive Summary

The ecs-control codebase is a working, production-quality application that successfully manages Alibaba Cloud ECS instances. It demonstrates solid fundamentals: consistent use of prepared statements, good Chinese-language comments that explain intent, and a clear domain understanding.

However, the codebase shows clear signs of organic growth without refactoring cycles. The two largest files — `AliyunTrafficCheck.php` (1,893 lines) and `ConfigManager.php` (1,105 lines) — have absorbed responsibilities far beyond their original scope. An attempted refactoring (`InstanceActionService`, `Account` value object) was started but never completed, leaving dead code and duplicated logic scattered across the project. There are no tests.

The architecture would benefit most from: completing the started refactoring to eliminate the God Objects, adopting the already-written `Account` value object throughout the codebase, and extracting the monitoring loop's five distinct concerns into separate, testable classes.

---

## Architecture

### Current State

The application follows a layered-but-flat structure:

```
index.php  ──→  AliyunTrafficCheck  ──→  AliyunService (API calls)
                   │                      ConfigManager (settings + accounts + encryption)
                   │                      Database (SQLite)
                   │                      NotificationService (email + Telegram + webhook)
                   │                      DdnsService (Cloudflare DNS)
                   │                      TelegramControlService (Telegram bot control)
                   │                      FrontendResponseBuilder (API response formatting)
                   │                      InstanceActionService (UNUSED — dead code)
                   │
            monitor.php  ──→  AliyunTrafficCheck::monitor()
            telegram_worker.php  ──→  TelegramControlService (via Reflection)
```

The entry point (`index.php`) is a flat router that dispatches to `AliyunTrafficCheck` methods. There is no middleware, no dependency injection container, no service locator — all dependencies are instantiated directly in the `AliyunTrafficCheck` constructor. This is consistent with the project's "no framework" constraint and works well at this scale.

### Problems

**God Object: AliyunTrafficCheck (1,893 lines).** This class handles:
- Session/auth management (via `index.php` routing)
- Setup/initialization
- Configuration CRUD
- Logo upload
- Log retrieval and clearing
- Account testing and instance fetching
- ECS creation preview and execution
- Account group synchronization
- Traffic monitoring loop (290-line `monitor()`)
- Instance control (start/stop/delete/replace IP)
- DDNS synchronization
- Pending release processing
- Telegram bot integration
- Status/health response building

This is the classic "Manager" or "Controller" anti-pattern. At least six distinct concerns are tangled in one class.

**God Object: ConfigManager (1,105 lines).** This class is responsible for:
- Settings storage and retrieval
- Account CRUD and synchronization
- Encryption (sodium secretbox)
- Account group normalization
- Account group metrics calculation
- Network metadata resolution
- Schedule state management
- Traffic block state management
- DDNS settings
- Notification settings
- Billing cache

**Dead code: InstanceActionService.** This class (447 lines) duplicates approximately 80% of the methods already in `AliyunTrafficCheck`:
- `controlInstance()` — duplicate of `AliyunTrafficCheck::controlInstanceAction()`
- `deleteInstance()` — duplicate of `AliyunTrafficCheck::deleteInstanceAction()`
- `replaceInstanceIp()` — duplicate of `AliyunTrafficCheck::replaceInstanceIpAction()`
- `refreshAccount()` — duplicate of `AliyunTrafficCheck::refreshAccount()`
- `getAllManagedInstances()` — duplicate of `AliyunTrafficCheck::getAllManagedInstances()`
- `processPendingReleases()` — duplicate of `AliyunTrafficCheck::processPendingReleases()`
- `safeGetTraffic()`, `safeGetInstanceStatus()`, `isCredentialInvalid()` — duplicates
- `getAccountLogLabel()` — duplicate
- All DDNS helper methods — duplicates

The `InstanceActionService` is instantiated in `AliyunTrafficCheck::__construct()` (line 38) but its methods are **never called**. The `AliyunTrafficCheck` versions are used everywhere instead. This is a refactoring that was started, partially implemented, and then abandoned — the worst possible outcome, because it means the code now has two copies of the same logic that can diverge independently.

**Dead code: Account value object.** `src/Account.php` defines a well-typed value object with `fromDbRow()`, `logLabel()`, `maskedKey()`, `effectiveGroupKey()`, and `toArray()` — but it is **never imported or used anywhere** in the codebase. Every other file accesses account data as raw `$account['access_key_id']` arrays. The `fromDbRow()` factory and `toArray()` bridge method were designed to support incremental migration but no migration ever happened.

**Reflection abuse in telegram_worker.php.** The worker uses `ReflectionClass` to set private properties as accessible and extract `$db` and `$configManager` from the `AliyunTrafficCheck` instance:

```php
$ref = new ReflectionClass($app);
$dbProp = $ref->getProperty('db');
$dbProp->setAccessible(true);
$db = $dbProp->getValue($app);
```

This is a direct violation of encapsulation. The worker needs these dependencies but `AliyunTrafficCheck` provides no public accessors. The fix is simple: add `getDb()` and `getConfigManager()` public methods, or better, instantiate `TelegramControlService` with its own dependencies directly (it already receives them via constructor injection).

---

## The monitor() Method: A Case Study in Complexity

The `monitor()` method at lines 373-660 is the heart of the application. It is **287 lines** with up to **6 levels of nesting** and performs five distinct operations for each account in a single loop:

1. **Adaptive heartbeat** — decides whether to call the Alibaba Cloud API or use cached data
2. **Traffic circuit breaking** — checks if usage exceeds the threshold and stops the instance
3. **Scheduled start/stop** — checks if the current time matches configured schedules
4. **Monthly auto-start** — on the 1st of each month, starts stopped instances
5. **Keepalive** — detects unexpected stops and restarts the instance

Each of these concerns has its own nested conditionals, its own notification dispatch, its own log writing, and its own status update. They are all interleaved in one function with shared mutable state (`$status`, `$actions`, `$apiStatusLog`, `$traffic`).

Key symptoms of the complexity:

- `$status` is reassigned up to 5 times within the loop (lines 427, 525, 565, 585, 606) — tracking its value at any given line requires mental execution of every branch
- `$apiStatusLog` is a string that gets appended to across multiple independent code paths (lines 458, 470-472, 480, 511, 567, 587, 608, 629)
- `$shouldCheckApi` is force-set to `true` on minute `:00` (line 419) and then immediately overridden by `$forceRefresh` — `$forceRefresh` is always `false` (line 406) and never mutated, making this dead logic
- The credential-invalid suspension logic is duplicated across the API check section (lines 440-454) and the traffic circuit-breaking section (lines 498-512)
- Notification dispatch happens in at least 5 different places with slightly different patterns

**Recommendation**: Extract each of the five concerns into a dedicated class with a single `process(Account $account, MonitorContext $context): void` method. The monitoring loop becomes:

```php
foreach ($accounts as $account) {
    $ctx = new MonitorContext($account, $currentTime, $settings);
    $this->heartbeat->process($account, $ctx);
    $this->circuitBreaker->process($account, $ctx);
    $this->scheduler->process($account, $ctx);
    $this->monthlyStarter->process($account, $ctx);
    $this->keepalive->process($account, $ctx);
    $this->db->addLog('heartbeat', $ctx->buildLogLine());
}
```

---

## Code Duplication

Beyond the `InstanceActionService` dead code noted above, several duplication patterns exist in the active code:

### 1. getAccountLogLabel() duplicated across three classes

- `AliyunTrafficCheck::getAccountLogLabel()` (lines 134-152)
- `InstanceActionService::getAccountLogLabel()` (lines 428-437)
- `Account::logLabel()` (in the unused value object)

All three implement identical logic: prefer remark, then instance_name, then instance_id, then masked AK. The `Account` value object has the cleanest implementation.

### 2. safeGetTraffic() duplicated in two classes

- `AliyunTrafficCheck::safeGetTraffic()` (lines 1357-1396)
- `InstanceActionService::safeGetTraffic()` (lines 254-266)

The `AliyunTrafficCheck` version is more thorough (handles `ClientException`, `ServerException`, cURL errors separately), while `InstanceActionService` has a simplified version. If the simplified version were ever used, it would miss credential-invalid detection for `ServerException` errors.

### 3. isCredentialInvalidError() / isCredentialInvalid() duplicated

- `AliyunTrafficCheck::isCredentialInvalidError()` (lines 1323-1355) — 8 error codes
- `InstanceActionService::isCredentialInvalid()` (lines 279-287) — 5 error codes (missing 3)

The `AliyunTrafficCheck` version is more comprehensive. The `InstanceActionService` version is a stale copy.

### 4. DDNS helper methods duplicated

Every DDNS-related private method in `AliyunTrafficCheck` (lines 1731-1883) has an identical copy in `InstanceActionService` (lines 311-446): `syncDdnsForAccounts()`, `reconcileDdnsAfterAccountSync()`, `deleteDdnsForAccount()`, `deleteDdnsRecord()`, `getDdnsRecordNamesForAccounts()`, `buildDdnsRecordNameForAccount()`, `getDdnsGroupCounts()`, `getDdnsGroupKey()`, `resolveGroupRemark()`, `getEffectivePublicIp()`.

These are ~150 lines of duplicated private methods that should live in a single `DdnsSyncService` class.

### 5. Status labels duplicated across three classes

- `NotificationService::statusLabel()` (lines 228-240)
- `TelegramControlService::statusLabel()` (lines 748-762)
- Various inline `in_array($status, ['Starting', 'Stopping', ...])` checks

The status-to-label mapping and the lists of transient/stable states are repeated throughout the codebase.

---

## Naming and Consistency

### Positive Patterns

- **Chinese comments are descriptive and helpful.** They explain *why*, not *what*. Example: "CDT 返回的是 per-AK 月度总流量，同一 group 下所有实例共享 AK 和流量值。取第一条记录的 traffic_used，不 SUM。"
- **Database column names follow a consistent snake_case convention.**
- **Method names are generally descriptive.** `safeGetTraffic()`, `notifyStatusChangeIfNeeded()`, `shouldRunScheduleAt()` all communicate intent well.
- **The `ENC1` prefix on encrypted values** is a good versioning pattern.

### Issues

- **Inconsistent language mixing.** Class names and method names are in English, database columns in snake_case English, but array keys passed between methods mix both (`AccessKeyId`, `groupKey`, `access_key_id`). The account group normalization methods translate between these two naming conventions (e.g., lines 692-706 in ConfigManager), which is fragile.
- **`accountLabel` vs `accessKeyId` parameter confusion.** `NotificationService::sendTrafficWarning()` takes `$accessKeyId` as its first parameter but uses it as an account label (description text), not an actual AccessKey ID. The parameter is later masked with `substr($accessKeyId, 0, 7) . '***'` — this works because callers pass the `$accountLabel` string, but the parameter name is misleading.
- **Inconsistent boolean storage.** Some booleans are stored as `'1'`/`'0'` strings in settings, others as integers `1`/`0` in the database, and accessed with `!empty()` (which treats `'0'` as falsy). This has caused at least one workaround: `ConfigManager::normalizeAccountGroups()` checks for both `scheduleStartEnabled` and `schedule_start_enabled` keys (line 685).
- **Magic numbers scattered throughout.** The monitor loop contains hardcoded values: `60` (transient interval), `500000` (retry usleep), `8` (sleep after start), `600` (DDNS sync interval), `5` (schedule window minutes), `90` (metric delay seconds). The `AliyunService` retry logic has `3` (max retries) and `1000000` (base backoff). These should be named constants or configuration values.
- **`$forceRefresh = false` is dead code.** Declared at line 406, never mutated, and always false when checked at line 417.

---

## Error Handling

### Positive Patterns

- **API calls have retry logic.** `AliyunService::executeWithRetry()` handles transient failures with exponential backoff and jitter. It correctly distinguishes between retryable errors (5xx, network) and non-retryable errors (4xx auth failures).
- **Safe wrappers prevent crashes.** `safeGetTraffic()`, `safeGetInstanceStatus()`, `safeControlInstance()` catch exceptions and return safe fallback values, preventing one failing account from breaking the entire monitoring loop.
- **Logging is pervasive.** Nearly every error path writes to the database log with context about which account and operation failed.

### Issues

- **Inconsistent return types for errors.** The codebase uses three different error signaling patterns:
  1. Return `false` on failure (e.g., `controlInstanceAction()`, `updateConfig()`)
  2. Throw exceptions (e.g., `fetchInstances()`, `testAccountCredentials()`)
  3. Return `['success' => false, 'message' => '...']` arrays (e.g., `replaceInstanceIpAction()`, `uploadLogo()`)

  Callers need to know which pattern each method uses. For example, `controlInstanceAction()` returns `false` on failure but `true` even when `$result` is falsy (line 1500-1517 — the method returns `true` unconditionally after the try block, regardless of whether the API call succeeded).

- **controlInstanceAction() always returns true.** Looking at lines 1490-1528: the method catches all exceptions and returns `false`, but the success path returns `true` at line 1517 regardless of whether `$this->aliyunService->controlInstance()` returned true or false. The `$result` variable is checked at line 1500 to decide whether to log "成功" and update status, but the method itself returns `true` either way. A failed API call where the exception is not thrown (e.g., the SDK returns an error response without throwing) would be reported as success to the caller.

- **safeGetTraffic() silently swallows the actual error in the generic catch.** Lines 1387-1395: the generic `\Exception` catch checks for "cURL error" in the message, but for all other exceptions it logs a stripped message and returns a generic `'sync_error'` status. The original exception type and stack trace are lost.

- **Exception messages exposed to frontend.** In `index.php`, many error responses pass `$e->getMessage()` directly to the JSON response (e.g., lines 70, 88, 235, 253). While the Alibaba Cloud SDK exceptions are generally safe, internal PHP exceptions could leak file paths or SQL details. The `strip_tags()` calls in some places (like `ConfigManager::updateConfig()` line 370) mitigate XSS but don't prevent information disclosure.

- **No dead-letter queue or retry for failed notifications.** If all three notification channels fail, the `dispatchNotifications()` method returns the concatenated error string. There is no retry mechanism, no queue, and no way to know that a critical alert (like "traffic exceeded, instance not stopped") was never delivered.

---

## Type Safety

- **PHP 8.1+ typed properties are used in `Account.php`** (the unused value object) — `public readonly int $id`, `public readonly string $accessKeyId`, etc. This is excellent and should be the pattern everywhere.
- **No other class uses typed properties.** `ConfigManager`, `AliyunTrafficCheck`, `NotificationService`, etc. all use untyped `private $foo` declarations.
- **No return type declarations.** Only `FrontendResponseBuilder` uses return type hints (`: array`, `: ?array`). No other class declares return types, making static analysis impossible.
- **Array shapes are undocumented.** Every method that returns or accepts an account array has an implicit contract about which keys exist and their types. These contracts exist only in the developer's head. A missing key results in a silent `null` from the `??` operator rather than an error.
- **`isset()` vs `??` vs `!empty()` inconsistency.** The codebase uses all three for default value handling with no clear convention. `!empty()` is particularly dangerous because it treats `'0'`, `0`, `false`, `''`, and `[]` as equivalent.

---

## Database Design

### Positive Patterns

- **WAL mode enabled** for better concurrent read performance.
- **Prepared statements used consistently** throughout — no raw SQL string concatenation with user input.
- **`INSERT OR REPLACE` and `ON CONFLICT` upsert patterns** are used correctly for idempotent operations.
- **Good use of composite unique indexes** (e.g., `UNIQUE(account_id, instance_id, billing_month)`).
- **Soft delete pattern** (`is_deleted` flag with states 0/1/2) for safe instance removal with reconciliation.

### Issues

- **Schema migrations via `ensureColumn()` are fragile.** Adding columns one-by-one with `ALTER TABLE ADD COLUMN` works for SQLite but there is no version tracking. If two deployments add different columns, there is no way to detect schema drift.
- **No foreign key constraints.** SQLite supports them when `PRAGMA foreign_keys = ON` is set, but this codebase doesn't use them. Deleted accounts could leave orphaned records in `traffic_hourly`, `traffic_daily`, `billing_cache`, `instance_traffic_usage`, and `ecs_create_tasks`.
- **`settings` table is a key-value store without typing.** Every setting value is a `TEXT` column. Boolean settings are stored as `'1'`/`'0'`, integers as strings, JSON as strings. This works but eliminates any possibility of database-level validation.
- **No database indexes on frequently queried columns.** `logs.type` and `logs.created_at` are used in `WHERE` and `ORDER BY` clauses but have no indexes, so every log query does a full table scan. For a monitoring system that writes one log row per account per minute, this will degrade over time even with pruning.

**Recommendation**: Add `CREATE INDEX IF NOT EXISTS idx_logs_type_created ON logs(type, created_at)` to the schema initialization.

---

## Testing

**There are no tests.** No PHPUnit configuration, no test directory, no test files. This is the single largest risk to the codebase's long-term maintainability.

The monitoring logic in `monitor()` is particularly高风险 (high-risk) to modify without tests, because:
- It interacts with external APIs (Aliyun Cloud) that are hard to mock
- It has complex state transitions across multiple concerns
- A bug in the circuit-breaking logic could result in unexpected cloud bills
- A bug in the keepalive logic could leave instances running indefinitely

**Recommendation**: Start with integration tests for the `Database` and `ConfigManager` classes (they are pure logic with no external API calls). Then add unit tests for the extracted monitoring concerns once the refactoring is done. Use PHPUnit mocks for `AliyunService` to simulate API responses.

---

## Performance Considerations

- **API response caching.** The traffic API result is cached per AccessKey in `AliyunService::$trafficCache` (line 78), avoiding duplicate calls within the same request. The billing cache in the database (6-hour TTL) reduces expensive BSS API calls.
- **Memory-efficient account iteration.** The monitor loop uses a single-pass foreach with no intermediate arrays.
- **Log pruning prevents unbounded growth.** Heartbeat logs (3 day retention) and general logs (30 day retention) prevent the database from growing indefinitely.
- **Potential N+1 for billing cache.** In `refreshAccount()`, the method checks balance cache, then instance bill cache sequentially. For the "sync all" flow, each account triggers its own billing lookups, which could be batched.
- **sleep(8) in controlInstanceAction() blocks the request.** After starting an instance, the method sleeps for 8 seconds to wait for status propagation. This blocks the HTTP response and the PHP-FPM worker. A better approach would be to return immediately and let the frontend poll for the updated status.

---

## Frontend Template (template.html)

The template was not reviewed in depth, but two observations from the initial read:

- **Vue 3 is loaded from CDN with no SRI hash** — see security audit H-3 for the supply chain risk.
- **The template references `static/tailwind-compiled.css`** — a compiled CSS file that appears to be pre-built and committed. This is consistent with the "no build step" constraint but means Tailwind changes require a manual recompile step that could be forgotten.

---

## Summary of Recommendations

### Immediate (reduce risk of bugs)

1. **Delete or complete the `InstanceActionService`.** Either remove the dead code (447 lines of unmaintained duplicates) or finish the migration and delete the duplicate methods from `AliyunTrafficCheck`.
2. **Adopt the `Account` value object.** The `Account` class is already written and well-typed. Use it everywhere instead of raw arrays. Start with `ConfigManager::getAccounts()` returning `Account[]`.
3. **Add `getDb()` and `getConfigManager()` accessors** to `AliyunTrafficCheck` and remove the Reflection hack in `telegram_worker.php`.

### Short-term (improve maintainability)

4. **Extract the `monitor()` method** into separate concern classes: `HeartbeatProcessor`, `CircuitBreaker`, `ScheduleRunner`, `MonthlyStarter`, `KeepaliveGuard`.
5. **Add database indexes** on `logs(type, created_at)`.
6. **Standardize error handling** — pick one pattern (return arrays with `success` key, or throw exceptions) and apply consistently.
7. **Add return type declarations** to all public methods.
8. **Extract DDNS helpers** into a `DdnsSyncService` that both `AliyunTrafficCheck` and `InstanceActionService` can use.
9. **Add a status enum or constants** for instance states (`Running`, `Stopped`, `Starting`, `Stopping`, etc.) instead of repeating string literals.

### Long-term (investment in quality)

10. **Write tests.** Start with `Database` and `ConfigManager` unit tests.
11. **Add a schema version table** and a proper migration system instead of ad-hoc `ensureColumn()` calls.
12. **Add foreign key constraints** with `PRAGMA foreign_keys = ON`.
13. **Consider extracting a `MonitorContext`** value object to replace the mutable local variables in the monitoring loop.
14. **Replace `sleep(8)` with async polling** in the start-instance flow.

---

## Metrics

| Metric | Value |
|--------|-------|
| Total PHP files | 14 |
| Total lines of PHP | ~6,500 |
| Largest file | `AliyunTrafficCheck.php` (1,893 lines) |
| Second largest | `ConfigManager.php` (1,105 lines) |
| Dead code | ~450 lines (`InstanceActionService`) + 179 lines (`Account` value object) |
| Duplicated methods | ~25 methods across 2 classes |
| Test coverage | 0% |
| Max nesting depth | 6 levels (`monitor()` circuit-breaker section) |
| Files with return types | 2 of 14 (`FrontendResponseBuilder`, `Account`) |
| Average method length | ~30 lines (skewed by very long `monitor()` at 287 lines) |
