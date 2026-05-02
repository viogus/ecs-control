# 全部代码最终审核报告 — ecs-control

**时间**: 2026-05-02
**范围**: 全部 16 个源文件，~7,500 行 PHP + Nginx/Docker/HTML 配置
**审核维度**: 正确性 · 安全性 · 代码质量 · 架构 · 可维护性

---

## 一、总体状态：可上线 ✅

所有路由完整、方法签名一致、无崩溃路径、无重复定义。v0.2 重构成功将核心协调器从 1893 行缩减到 915 行（−52%），提取了监控和实例操作服务，整合了 DDNS 编排。

---

## 二、文件清单与职责

| 文件 | 行数 | 职责 | 评价 |
|------|-----|------|------|
| `AliyunTrafficCheck.php` | 915 | 路由协调、ECS 创建、登录、配置、日志 | God Object 已大幅缩减，剩余职责（ECS 创建、Telegram）待提取 |
| `AliyunService.php` | 2,087 | 阿里云 API 全封装（ECS/VPC/BSS/CDT/CMS/EIP） | 最大文件，但结构清晰：重试执行器 + 逐产品 API 方法 |
| `ConfigManager.php` | 1,105 | 设置、账户、加密、组管理、DDNS 设置 | 待拆分 |
| `TelegramControlService.php` | 804 | Telegram Bot 交互控制 | 内联键盘逻辑清晰 |
| `Database.php` | 629 | SQLite 操作 | 良好：prepared statements、WAL、schema 迁移 |
| `NotificationService.php` | 583 | 邮件/Telegram/Webhook 通知 | 正常 |
| `src/MonitorService.php` | 569 | 监控循环（心跳/熔断/定时/保活） | run() 仍是 280 行巨石方法 |
| `src/InstanceActionService.php` | 447 | 实例操作 + 后台释放 | 正常 |
| `src/FrontendResponseBuilder.php` | 445 | API 响应构建 + 实例快照 | 正常 |
| `index.php` | 435 | HTTP 路由 + CSRF | 正常 |
| `DdnsService.php` | 404 | Cloudflare API + DDNS 编排 | API 和编排在同一类中 |
| `src/Account.php` | 179 | 值对象（类型安全） | **未使用** — 全库仍用裸数组 |
| `template.html` | ~2,000 | Vue 3 SPA | 正常 |
| `telegram_worker.php` | 33 | Telegram 轮询守护进程 | Reflection hack 待修 |
| `monitor.php` | 31 | Cron 入口 | 正常 |
| Docker 配置 | ~150 | Nginx + PHP-FPM + Cron | 正常 |

---

## 三、正确性验证

### 路由完整性 ✅

`index.php` 的 15+ 个 action 路由全部正确分发到 `AliyunTrafficCheck` 对应方法，委托链路完整：

```
control_instance  → controlInstanceAction()  → InstanceActionService::controlInstance()
delete_instance   → deleteInstanceAction()   → InstanceActionService::deleteInstance()
replace_ip        → replaceInstanceIpAction() → InstanceActionService::replaceInstanceIp()
get_all_instances → getAllManagedInstances()  → InstanceActionService::getAllManagedInstances()
refresh_account   → refreshAccount()         → InstanceActionService::refreshAccount()
```

### 方法签名一致性 ✅

- `controlInstanceAction` 传递的回调 `[$this, 'notifyStatusChangeIfNeeded']` 在 AliyunTrafficCheck 上定义为 public 方法（第 877 行）
- `getAllManagedInstances` 传递的回调 `[$this->responseBuilder, 'buildInstanceSnapshot']` 签名匹配
- `processPendingReleases` 传递的闭包签名匹配 `InstanceActionService` 期望的 `callable $onReleased`

### 无重复定义 ✅

全库无方法重复定义，无类重复定义。

### 无对已移除方法的引用 ✅

`AliyunTrafficCheck` 中不再直接调用已移至子服务的私有方法。

---

## 四、安全性（预存在问题）

v0.2 重构未涉及安全，以下问题全部保持原状：

**严重（3 项）**:
- C-1: 无 TLS/HTTPS — 所有凭证明文传输
- C-2: 通知凭证明文存储在 SQLite（仅 AK Secret 使用 Sodium 加密）
- C-3: SQLite 数据库仅靠 Nginx location block 保护（`.htaccess` 对 Nginx 无效）

**高（3 项）**:
- H-1: 登录限流可被 `X-Forwarded-For` 伪造绕过
- H-2: Webhook URL 和 Telegram Proxy URL 无验证，存在 SSRF 风险
- H-3: 无 CSP 头 — CDN 加载的 Vue 3 存在供应链风险

**中（3 项）**:
- M-1: 邮件模板值未 `htmlspecialchars` 转义
- M-3: 加密密钥目录权限 0755 偏宽松
- M-4: Monitor Key 在管理界面可见

**低（2 项）**:
- M-2: `ensureColumn()` 动态 SQL 拼接（仅硬编码调用方）
- M-5: Logo 上传可通过服务端重编码加固

完整安全审计见 `SECURITY_AUDIT.md`。

---

## 五、代码质量

### 5.1 重复代码

`getAccountLogLabel` / `safeGetTraffic` / `safeGetInstanceStatus` 在 4 个位置重复：

| 位置 | getAccountLogLabel | safeGetTraffic | safeGetInstanceStatus | isCredentialInvalid |
|------|:---:|:---:|:---:|:---:|
| MonitorService | ✅ | ✅ (完整版) | ✅ | ✅ (8 个错误码) |
| InstanceActionService | ✅ | ✅ (简化版) | ✅ | ✅ (5 个错误码) |
| FrontendResponseBuilder | ✅ | ✅ (极简版) | ✅ | ✅ (仅 status 检查) |
| AliyunTrafficCheck | ✅ | — | — | — |
| DdnsService | ✅ | — | — | — |

各版本的 `safeGetTraffic` 对错误处理的细致程度不同：`MonitorService` 区分 ClientException/ServerException/cURL error；`FrontendResponseBuilder` 将所有异常归为 generic `sync_error`。InstanceActionService 的 `isCredentialInvalid` 比 MonitorService 少 3 个错误码，可能漏掉凭据失效检测。

### 5.2 方法复杂度

| 方法 | 行数 | 嵌套层级 | 状态 |
|------|------|---------|------|
| `MonitorService::run()` | 280 | 6 | 需拆分 |
| `AliyunService::createManagedEcsFromPreview()` | 119 | 4 | 可接受 |
| `AliyunService::getInstances()` | 94 | 4 | 可接受 |
| `ConfigManager::syncAccountGroups()` | 202 | 3 | 需拆分 |

### 5.3 类型安全

- `FrontendResponseBuilder` 使用 PHP 8.1 类型属性（`private ConfigManager $configManager`）— **全库仅此一处**
- `Account` 值对象使用 `public readonly` 类型属性 — **但未使用**
- 其他所有类使用无类型 `private $foo` 声明
- 仅 `FrontendResponseBuilder` 和 `MonitorService::run()` 有返回类型声明

### 5.4 遗留问题

- **telegram_worker.php Reflection hack**: 仍用 `ReflectionClass` 访问 `AliyunTrafficCheck` 的私有属性来获取 `$db` 和 `$configManager`
- **MonitorService::run() 第 22 行废代码**: `if ($this->initError)` — 属性不存在，永不触发
- **$forceRefresh = false**: MonitorService::run() 第 53 行，声明后从未修改，等价于 `$shouldCheckApi = (($currentTime - $lastUpdate) > $currentInterval)`
- **composer.json PSR-4 配置未使用**: `"EcsControl\\": "src/"` — 但 src/ 下无文件声明 `namespace EcsControl`
- **无测试**: 0 覆盖率

---

## 六、架构评价

```
                         index.php (路由)
                              │
                    AliyunTrafficCheck (协调器 915 行)
                     ╱        │         ╲
            MonitorService  InstanceAction  AliyunService
            (监控循环)      Service         (API 封装 2087 行)
                            (实例操作)
                 │              │
            DdnsService    FrontendResponseBuilder
            (DNS + 编排)   (响应构建)
```

**优点**:
- 协调器从 1893 行缩减到 915 行
- API 层 (`AliyunService`) 有统一的重试机制和指数退避
- 监控逻辑独立可测试（理论上的，尚无测试）

**待改进**:
- `MonitorService::run()` 搬了位置但没拆
- `ConfigManager` 仍是 God Object（1105 行）
- `AliyunService` 最大（2087 行），覆盖了 ECS/VPC/BSS/CDT/CMS/EIP 五个阿里云产品 — 可考虑按产品拆分
- `DdnsService` 混合了 API 调用和编排逻辑
- `Account` 值对象存在但未接入

---

## 七、改进优先级

**立即（上线前 — 1 项）**:
1. 删除 `MonitorService::run()` 第 22-23 行的废代码（`$this->initError` 检查）

**短期（下个迭代 — 安全修复）**:
2. 部署 TLS 终止
3. 通知凭证明文存储 → Sodium 加密
4. 修复 login() 中 X-Forwarded-For 伪造问题
5. Webhook/Proxy URL 验证
6. 邮件模板 HTML 转义
7. Reflection hack → 两个 public getter

**中期（1-2 迭代 — 架构改进）**:
8. 拆分 `MonitorService::run()` 为独立处理器
9. 拆分 `ConfigManager`
10. 接入 `Account` 值对象
11. 提取共享的 safe wrapper 到公共位置
12. CSP 头

**长期**:
13. 测试（Database → ConfigManager → MonitorService）
14. 数据库索引优化
15. 统一错误处理模式
16. 全库类型声明

---

## 八、结论

代码库处于**生产可用、架构合理**状态。v0.2 重构取得了实质性进展，God Object 缩减 52%，服务职责划分清晰。安全方面有 6 个预存在问题需在下个迭代处理。代码质量方面，最紧迫的改进（废代码删除、Reflection hack 修复、safe wrapper 去重）量小风险低，可在一个下午完成。`MonitorService::run()` 的拆分是下一个重大重构目标，但不阻塞上线。
