# Re-Review — v0.2 Refactoring (post-fix)

**Date**: 2026-05-02
**Reviewed**: Fix commit `83020ce` ("Fix regressions from MonitorService extraction") applied on top of v0.2 refactoring
**Scope**: Verify all 5 previously-reported bugs are resolved and check for new regressions

---

## Bug Resolution Status

| Bug | Description | Status |
|-----|-------------|--------|
| BUG-1 | `$this->initError` guard in MonitorService | **Not fixed** (dead code, no crash) |
| BUG-2 | `$this->processTelegramControl()` in MonitorService::run() | **Fixed** ✅ |
| BUG-3 | Stale delegation methods in MonitorService (lines 575-608) | **Fixed** ✅ |
| BUG-4 | Frontend control methods missing from AliyunTrafficCheck | **Fixed** ✅ |
| BUG-5 | `refreshAccount()` calls removed `safeGetTraffic/safeGetInstanceStatus` | **Fixed** ✅ |

**4 of 5 bugs are resolved.** BUG-1 remains as harmless dead code.

---

## New Regressions Introduced by the Fix

### REGRESSION-1: Duplicate method `getEcsCreateTask()` — Fatal Error

**File**: `AliyunTrafficCheck.php`, lines 786 and 876
**Severity**: **Fatal error on class load**

```php
// Line 786 (original — kept during refactoring)
public function getEcsCreateTask($taskId)
{
    if ($this->initError) {
        return null;
    }
    return $this->db->getEcsCreateTask($taskId);
}

// Line 876 (accidentally duplicated in the fix commit)
public function getEcsCreateTask($taskId)
{
    if ($this->initError) return null;
    return $this->db->getEcsCreateTask($taskId);
}
```

PHP will throw `Fatal error: Cannot redeclare AliyunTrafficCheck::getEcsCreateTask()` when the class is loaded. This would crash every request, including the login page.

**Fix**: Delete lines 876-880 (the duplicate copy). The original at line 786 is sufficient.

---

### REGRESSION-2: Missing callback method `notifyStatusChangeIfNeeded` — Runtime Crash

**File**: `AliyunTrafficCheck.php`, line 855
**Severity**: **Fatal error when starting an instance from web UI**

```php
public function controlInstanceAction($accountId, $action, $shutdownMode = 'KeepCharging', $waitForSync = true)
{
    if ($this->initError) return false;
    return $this->instanceActionService->controlInstance(
        $accountId, $action, $shutdownMode, $waitForSync,
        [$this, 'notifyStatusChangeIfNeeded']  // ← method removed from AliyunTrafficCheck
    );
}
```

`notifyStatusChangeIfNeeded` was a private method on `AliyunTrafficCheck` that was moved to `MonitorService` during the refactoring. The `InstanceActionService::controlInstance()` invokes this callback when a start action succeeds and the instance reaches `Running` state after the 8-second sync window:

```php
// InstanceActionService.php line 44-46:
if (($syncedAccount['instance_status'] ?? '') === 'Running' && $onStatusChanged) {
    $onStatusChanged($syncedAccount, $targetAccount['instance_status'] ?? 'Unknown', 'Running', '用户手动启动成功。');
}
```

When the callback fires, PHP will throw `Fatal error: Call to undefined method AliyunTrafficCheck::notifyStatusChangeIfNeeded()`.

This affects the "Start Instance" button in the web UI. The stop/delete/replace-IP actions don't invoke this callback, so they would work fine.

**Fix**: Add `notifyStatusChangeIfNeeded` back as a public method on `AliyunTrafficCheck`, delegating to a new `MonitorService` instance or calling `NotificationService` directly:

```php
public function notifyStatusChangeIfNeeded($account, $fromStatus, $toStatus, $reason = '')
{
    $fromStatus = (string) ($fromStatus ?: 'Unknown');
    $toStatus = (string) ($toStatus ?: 'Unknown');
    
    if ($fromStatus === $toStatus || !in_array($toStatus, ['Running', 'Stopped'], true)) {
        return;
    }
    if ($fromStatus === 'Unknown') return;
    
    $accountLabel = $this->getAccountLogLabel($account);
    $result = $this->notificationService->notifyInstanceStatusChanged(
        $accountLabel, $account, $fromStatus, $toStatus, $reason
    );
    if ($result === true) {
        $this->db->addLog('info', "通知推送成功 [$accountLabel]");
    } elseif ($result !== false && $result !== true) {
        $this->db->addLog('warning', "通知推送异常/失败 [$accountLabel]: " . strip_tags($result));
    }
}
```

Alternatively, change the callback at line 855 to a closure that creates a temporary `MonitorService` instance and calls its private method — but adding the method back is simpler and more readable.

---

## Remaining Low-Severity Issue

### BUG-1 (still present): Dead `$this->initError` guard in MonitorService

**File**: `src/MonitorService.php`, lines 22-23

```php
public function run(): string
{
    if ($this->initError)            // undefined property — always null/falsy
        return "错误: " . $this->initError;
```

This is harmless since `$this->initError` is an undefined property (resolves to `null`), so the guard never triggers. The caller (`AliyunTrafficCheck::monitor()`) already checks `$this->initError` before instantiating `MonitorService`, making this guard redundant even if it worked.

**Recommendation**: Remove lines 22-23 to avoid confusion for future maintainers.

---

## Updated Assessment

The fix commit correctly resolved 4 of 5 bugs but introduced 2 new regressions:

| Issue | Impact | Fix difficulty |
|-------|--------|---------------|
| REGRESSION-1: Duplicate `getEcsCreateTask()` | **Blocks all requests** — fatal error on class load | Trivial (delete 5 lines) |
| REGRESSION-2: Missing `notifyStatusChangeIfNeeded` | **Crashes start-instance flow** when instance reaches Running | Easy (add ~20-line method) |
| BUG-1: Dead `$this->initError` guard | None (dead code only) | Trivial (delete 2 lines) |

**Verdict**: After fixing the two regressions above, the v0.2 refactoring is production-ready. The structural improvements are solid: `AliyunTrafficCheck` is 49% smaller, DDNS orchestration is consolidated, and `InstanceActionService` is properly wired. The remaining code quality issues (deeply nested monitor loop, duplication between MonitorService and InstanceActionService helpers) are pre-existing and were not addressed by this refactoring wave.
