# AccountRefresher: Extract Shared Refresh Logic

**Date**: 2026-05-15
**Status**: Design

## Problem

`MonitorService::handleAdaptiveHeartbeat` (85 lines) and `FrontendResponseBuilder::buildInstanceSnapshot` (120 lines) independently implement the same traffic/status refresh pipeline: fetch CDT traffic, fetch instance status, detect credential invalid, handle API failures, write stats, and persist via `updateAccountStatus()`. The duplication creates a maintenance hazard — changes to traffic fetching, status checking, or credential validation must be applied to both files.

## Design

### New class: `AccountRefresher`

Single responsibility: given an Account, fetch fresh traffic + status from Alibaba Cloud and persist the results. Returns a `RefreshResult` DTO. Callers apply their specific post-processing (logging, DTO building, health checks, auth recovery).

```
AccountRefresher
├── refresh(Account, int currentTime): RefreshResult
├── safeGetTraffic(Account): array
├── safeGetInstanceStatus(Account): string
└── isCredentialInvalidTrafficStatus(string): bool
```

**Dependencies**: `Database`, `AliyunService`, `ConfigManager`

### `RefreshResult` value object

```php
class RefreshResult {
    public float $traffic;
    public string $status;
    public array $metadata;
    public int $newUpdateTime;
}
```

### `refresh()` pipeline

1. Fetch CDT traffic via `Helpers::safeGetCdtTraffic()`
2. Fetch instance status via `AliyunService::getInstanceStatus()`
3. If Unknown, retry once with 500ms delay (safe for both callers)
4. Build metadata: `traffic_api_status`, `traffic_api_message`
5. Detect credential invalid from traffic result status
6. If auth invalid: set `protection_suspended=1`, `protection_suspend_reason=credential_invalid` in metadata
7. On traffic success: use new value, write hourly/daily stats, update timestamp
8. On traffic failure: use cached value, keep old timestamp
9. Persist via `ConfigManager::updateAccountStatus()`
10. Return `RefreshResult`

### Caller responsibilities after refresh

| Concern | MonitorService | FrontendResponseBuilder |
|---------|---------------|------------------------|
| Auth recovery (clear suspended flag) | Check if previously suspended + auth now OK → clear, log | Set metadata to 0 if not auth_invalid |
| protection_suspend_notified_at | Manage in `$s[]` state | Hardcode 0 in metadata |
| Health check | Skip | `safeGetInstanceFullStatus()` on Running |
| Status logging | Append to `$s['apiStatusLog']` | None |
| DTO building | Store in `$s[]` state array | Build full snapshot array |

### What gets removed

**From MonitorService::handleAdaptiveHeartbeat** (~50 lines removed):
- Traffic fetching
- Status fetching + retry
- Metadata building
- Credential detection
- Traffic success/failure handling
- Stats writing
- `updateAccountStatus()` call

**From FrontendResponseBuilder::buildInstanceSnapshot** (~45 lines removed):
- Same pipeline, plus the `isCredentialInvalidTrafficStatus` method (moves to refresher)

### What stays

- Interval calculation logic (transient vs stable)
- API call gating (time check, force refresh, minute-00)
- Auth recovery logging (Monitor)
- Health check (FRB)
- DTO/snapshot building (FRB)
- All downstream phase logic in MonitorService (circuit breaker, schedules, keepalive)

### Files changed

| File | Change |
|------|--------|
| `src/AccountRefresher.php` | **New** — refresher class + RefreshResult |
| `src/MonitorService.php` | Remove ~50 lines, delegate to refresher |
| `src/FrontendResponseBuilder.php` | Remove ~45 lines, delegate to refresher |
| `src/AppContainer.php` | Add `getAccountRefresher()` |

### Auto-loading

`composer.json` classmap covers `./` — new file auto-discovered. AppContainer requires it explicitly (consistent with other `src/` services).

### Tests

Existing `MonitorServiceTest` and `HttpRouterTest` continue to pass. `handleAdaptiveHeartbeat` and `buildInstanceSnapshot` are integration-tested through the existing test pattern (reflection on private methods with fakes). The refresher itself can be unit-tested separately if needed.
