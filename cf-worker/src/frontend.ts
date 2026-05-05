export function renderHtml(csrfToken: string): string {
  return `<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ECS 服务器管家</title>
<script src="https://cdn.jsdelivr.net/npm/vue@3/dist/vue.global.prod.js"></script>
<script>window.Vue||document.write('<script src="https://unpkg.zhimg.com/vue@3/dist/vue.global.prod.js"><\\/script>');</script>
<noscript><div style="background:#ff3b30;color:#fff;padding:12px;text-align:center">请启用 JavaScript 以使用管理面板</div></noscript>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,sans-serif;background:#f5f5f7;color:#1d1d1f}
.container{max-width:960px;margin:0 auto;padding:16px}
.card{background:#fff;border-radius:16px;padding:20px;margin-bottom:12px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:22px}
h2{font-size:17px;margin-bottom:12px}
.btn{padding:7px 16px;border-radius:8px;border:none;cursor:pointer;font-size:13px;font-weight:500}
.btn-primary{background:#007aff;color:#fff}
.btn-danger{background:#ff3b30;color:#fff}
.btn-outline{background:#fff;color:#007aff;border:1px solid #007aff}
.btn-sm{padding:4px 10px;font-size:12px}
input,select,textarea{padding:8px 12px;border:1px solid #d2d2d7;border-radius:8px;font-size:14px;width:100%}
label{font-size:13px;color:#86868b;display:block;margin-bottom:3px}
.form-group{margin-bottom:10px}
.row{display:flex;gap:12px;flex-wrap:wrap}
.row>.form-group{flex:1;min-width:180px}
.status-badge{padding:2px 8px;border-radius:12px;font-size:11px;font-weight:600}
.status-Running{background:#34c759;color:#fff}
.status-Stopped{background:#ff3b30;color:#fff}
.status-Stopping,.status-Starting,.status-Pending,.status-Releasing{background:#ff9500;color:#fff}
.tabs{display:flex;gap:4px;margin-bottom:16px;border-bottom:2px solid #e5e7eb;padding-bottom:0}
.tab{padding:8px 20px;border-radius:8px 8px 0 0;cursor:pointer;font-size:14px;font-weight:500;color:#86868b;background:transparent;border:none}
.tab.active{background:#fff;color:#007aff;border:2px solid #e5e7eb;border-bottom-color:#fff;margin-bottom:-2px}
.log-line{font-size:13px;padding:6px 8px;border-bottom:1px solid #f0f0f0;font-family:monospace}
.log-line:nth-child(odd){background:#fafafa}
.toast{position:fixed;top:16px;right:16px;padding:10px 20px;border-radius:8px;color:#fff;font-size:14px;z-index:999;animation:fade 3s forwards}
.toast-success{background:#34c759}
.toast-error{background:#ff3b30}
@keyframes fade{0%,80%{opacity:1}100%{opacity:0}}
.spinner{display:inline-block;width:14px;height:14px;border:2px solid #ccc;border-top-color:#007aff;border-radius:50%;animation:spin .6s linear infinite;margin-right:6px}
@keyframes spin{to{transform:rotate(360deg)}}
.toggle{position:relative;display:inline-block;width:44px;height:24px}
.toggle input{opacity:0;width:0;height:0}
.toggle .slider{position:absolute;cursor:pointer;inset:0;background:#ccc;border-radius:24px;transition:.2s}
.toggle .slider:before{content:'';position:absolute;height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked+.slider{background:#34c759}
.toggle input:checked+.slider:before{transform:translateX(20px)}
.separator{height:1px;background:#e5e7eb;margin:16px 0}
</style>
</head>
<body>
<div id="app">
<div class="container">
  <!-- Login / Setup -->
  <div v-if="!loggedIn">
    <div class="card">
      <h1>ECS 服务器管家</h1>
      <div v-if="!initialized">
        <div class="form-group"><label>管理员密码（至少 8 个字符）</label><input v-model="setupPassword" type="password"></div>
        <div class="form-group"><label>迁移数据 (可选，粘贴 Docker 导出的 JSON)</label><textarea v-model="migrationJson" rows="4"></textarea></div>
        <button class="btn btn-primary" @click="doSetup" :disabled="working">{{ working ? '初始化中...' : '初始化' }}</button>
        <p v-if="initMsg" style="margin-top:8px;color:#ff3b30">{{ initMsg }}</p>
      </div>
      <div v-else>
        <div class="form-group"><label>密码</label><input v-model="loginPassword" type="password" @keyup.enter="doLogin"></div>
        <button class="btn btn-primary" @click="doLogin" :disabled="working">{{ working ? '登录中...' : '登录' }}</button>
        <p v-if="loginMsg" style="margin-top:8px;color:#ff3b30">{{ loginMsg }}</p>
      </div>
    </div>
  </div>

  <!-- Admin Panel -->
  <div v-else>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:12px">
      <h1>ECS 服务器管家</h1>
      <button class="btn btn-outline btn-sm" @click="doLogout">退出</button>
    </div>

    <div class="tabs">
      <button class="tab" :class="{active:tab==='instances'}" @click="tab='instances'">实例监控</button>
      <button class="tab" :class="{active:tab==='ddns'}" @click="tab='ddns'">DDNS</button>
      <button class="tab" :class="{active:tab==='logs'}" @click="tab='logs'">系统日志</button>
      <button class="tab" :class="{active:tab==='settings'}" @click="tab='settings'">系统设置</button>
    </div>

    <!-- Tab: Instances -->
    <div v-if="tab==='instances'">
      <div v-if="loading" style="text-align:center;padding:40px"><span class="spinner"></span>加载中...</div>
      <div v-else-if="instances.length===0" class="card"><p style="color:#86868b">暂无实例数据</p></div>
      <div v-for="inst in instances" :key="inst.id" class="card" style="position:relative">
        <div style="display:flex;justify-content:space-between;align-items:start">
          <div>
            <strong>{{ inst.remark || inst.instanceName || inst.instanceId }}</strong>
            <span class="status-badge" :class="'status-'+inst.instanceStatus" style="margin-left:8px">{{ statusText(inst.instanceStatus) }}</span>
          </div>
          <div style="display:flex;gap:6px">
            <button v-if="inst.instanceStatus==='Running'" class="btn btn-outline btn-sm" @click="doControl(inst.id,'stop')">停机</button>
            <button v-if="inst.instanceStatus==='Stopped'" class="btn btn-primary btn-sm" @click="doControl(inst.id,'start')">开机</button>
            <button v-if="inst.instanceStatus==='Running'||inst.instanceStatus==='Stopped'" class="btn btn-danger btn-sm" @click="confirmDelete(inst)">释放</button>
          </div>
        </div>
        <div style="font-size:13px;color:#86868b;margin-top:8px;display:grid;grid-template-columns:1fr 1fr;gap:4px">
          <div>实例 ID: {{ inst.instanceId }}</div>
          <div>规格: {{ inst.instanceType }} ({{ inst.cpu }}C{{ inst.memory }}G)</div>
          <div>区域: {{ inst.regionId }}</div>
          <div>公网 IP: {{ inst.publicIp || '-' }}</div>
          <div>出口流量: {{ fmtTraffic(inst.traffic_used) }} / {{ fmtTraffic(inst.max_traffic) }}</div>
          <div>IP 模式: {{ inst.publicIpMode==='eip' ? 'EIP' : 'ECS 公网' }}</div>
        </div>
      </div>
      <button class="btn btn-outline btn-sm" @click="fetchInstances" :disabled="loading" style="margin-top:8px">刷新</button>
    </div>

    <!-- Tab: DDNS -->
    <div v-if="tab==='ddns'">
      <div class="card">
        <h2>Cloudflare DDNS</h2>
        <div class="form-group">
          <label>启用</label>
          <label class="toggle"><input type="checkbox" v-model="ddns.enabled" true-value="1" false-value="0"><span class="slider"></span></label>
        </div>
        <div class="form-group"><label>根域名</label><input v-model="ddns.domain" placeholder="example.com"></div>
        <div class="form-group"><label>Zone ID</label><input v-model="ddns.zoneId" placeholder="Cloudflare Zone ID"></div>
        <div class="form-group"><label>API Token</label><input v-model="ddns.token" placeholder="留空不修改" type="password"></div>
        <div class="form-group">
          <label>CDN 代理</label>
          <label class="toggle"><input type="checkbox" v-model="ddns.proxied" true-value="1" false-value="0"><span class="slider"></span></label>
        </div>
        <p style="font-size:12px;color:#86868b;margin-bottom:8px">记录名格式: 实例备注.根域名。DDNS 每 10 分钟由 Cron 自动同步一次。</p>
        <button class="btn btn-primary" @click="saveDdns" :disabled="working">{{ working ? '保存中...' : '保存 DDNS 配置' }}</button>
      </div>
    </div>

    <!-- Tab: Logs -->
    <div v-if="tab==='logs'">
      <div style="display:flex;gap:8px;margin-bottom:12px">
        <button class="btn btn-sm" :class="logTab==='action'?'btn-primary':'btn-outline'" @click="logTab='action';fetchLogs()">动作日志</button>
        <button class="btn btn-sm" :class="logTab==='heartbeat'?'btn-primary':'btn-outline'" @click="logTab='heartbeat';fetchLogs()">心跳日志</button>
        <button class="btn btn-danger btn-sm" @click="clearLogs" style="margin-left:auto">清空当前</button>
      </div>
      <div class="card" style="max-height:500px;overflow-y:auto">
        <div v-if="logs.length===0" style="color:#86868b;text-align:center;padding:20px">暂无日志</div>
        <div v-for="log in logs" :key="log.id" class="log-line">
          <span style="color:#86868b">{{ fmtTime(log.created_at) }}</span>
          <span style="margin-left:8px" :style="{color:log.type==='error'?'#ff3b30':log.type==='warning'?'#ff9500':'#1d1d1f'}">[{{ log.type }}]</span>
          <span style="margin-left:8px">{{ log.message }}</span>
        </div>
      </div>
    </div>

    <!-- Tab: Settings -->
    <div v-if="tab==='settings'">
      <div class="card">
        <h2>流量保护</h2>
        <div class="row">
          <div class="form-group"><label>流量阈值 (%)</label><input v-model.number="cfg.traffic_threshold" type="number" min="1" max="100"></div>
          <div class="form-group"><label>停机模式</label>
            <select v-model="cfg.shutdown_mode"><option value="KeepCharging">保持收费</option><option value="StopCharging">停机不收费</option></select>
          </div>
        </div>
        <div class="form-group">
          <label>保活</label>
          <label class="toggle"><input type="checkbox" v-model="cfg.keep_alive" true-value="1" false-value="0"><span class="slider"></span></label>
          <span style="font-size:12px;color:#86868b;margin-left:8px">实例意外停机后自动启动</span>
        </div>
      </div>

      <div class="card">
        <h2>通知</h2>
        <div class="form-group">
          <label>邮件通知</label>
          <label class="toggle"><input type="checkbox" v-model="cfg.notify_email_enabled" true-value="1" false-value="0"><span class="slider"></span></label>
        </div>
        <div class="row">
          <div class="form-group"><label>收件邮箱</label><input v-model="cfg.notify_email"></div>
          <div class="form-group"><label>SMTP 主机</label><input v-model="cfg.notify_host"></div>
          <div class="form-group"><label>端口</label><input v-model="cfg.notify_port"></div>
        </div>
        <div class="row">
          <div class="form-group"><label>用户名</label><input v-model="cfg.notify_username"></div>
          <div class="form-group"><label>密码</label><input v-model="cfg.notify_password" type="password" placeholder="留空不修改"></div>
          <div class="form-group"><label>加密</label><select v-model="cfg.notify_secure"><option value="ssl">SSL</option><option value="tls">TLS</option></select></div>
        </div>
        <div class="separator"></div>
        <div class="form-group">
          <label>Webhook 通知</label>
          <label class="toggle"><input type="checkbox" v-model="cfg.notify_wh_enabled" true-value="1" false-value="0"><span class="slider"></span></label>
        </div>
        <div class="row">
          <div class="form-group"><label>Webhook URL</label><input v-model="cfg.notify_wh_url"></div>
          <div class="form-group"><label>请求方式</label><select v-model="cfg.notify_wh_method"><option value="GET">GET</option><option value="POST">POST</option></select></div>
        </div>
        <button class="btn btn-outline btn-sm" @click="sendTestEmail" :disabled="working" style="margin-right:8px">测试邮件</button>
        <button class="btn btn-outline btn-sm" @click="sendTestWebhook" :disabled="working">测试 Webhook</button>
      </div>

      <div class="card">
        <h2>数据管理</h2>
        <button class="btn btn-outline btn-sm" @click="doExport" :disabled="working">导出 JSON（用于迁移至 Docker 版）</button>
        <span v-if="exportResult" style="margin-left:8px;font-size:13px">{{ exportResult }}</span>
      </div>

      <button class="btn btn-primary" @click="saveSettings" :disabled="working" style="margin-top:12px">{{ working ? '保存中...' : '保存设置' }}</button>
    </div>
  </div>

  <!-- Toast -->
  <div v-if="toast" class="toast" :class="'toast-'+toast.type">{{ toast.msg }}</div>
</div>
</div>
<script>
const { createApp } = Vue;
createApp({
  data() { return {
    // auth
    loggedIn: false, initialized: false,
    loginPassword: '', setupPassword: '', migrationJson: '',
    loginMsg: '', initMsg: '',
    token: '', csrfToken: ${JSON.stringify(csrfToken)},
    working: false, toast: null,

    // admin
    tab: 'instances', loading: false,
    instances: [],
    logs: [], logTab: 'action',
    exportResult: '',

    // config cache
    cfg: {
      traffic_threshold: '95', shutdown_mode: 'KeepCharging',
      keep_alive: '0',
      notify_email_enabled: '1', notify_email: '', notify_host: '', notify_port: '465',
      notify_username: '', notify_password: '', notify_secure: 'ssl',
      notify_wh_enabled: '0', notify_wh_url: '', notify_wh_method: 'GET',
    },

    // DDNS
    ddns: { enabled: '0', domain: '', zoneId: '', token: '', proxied: '0' },
  };},

  async mounted() {
    try {
      const r = await fetch('/api/check-init', {method:'POST'});
      const d = await r.json();
      this.initialized = d.initialized;
    } catch(e) { this.initMsg = '无法连接到服务器'; }
  },

  methods: {
    // ---- auth ----
    async doLogin() {
      this.working = true; this.loginMsg = '';
      try {
        const r = await fetch('/api/login', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:this.loginPassword})});
        const d = await r.json();
        if (d.success) {
          this.token = d.token; this.csrfToken = d.csrf_token; this.loggedIn = true;
          await Promise.all([this.fetchInstances(), this.fetchConfig()]);
        } else this.loginMsg = d.message || '密码错误';
      } catch(e) { this.loginMsg = '登录请求失败'; }
      finally { this.working = false; }
    },
    async doSetup() {
      this.working = true; this.initMsg = '';
      try {
        const body = { password: this.setupPassword };
        if (this.migrationJson.trim()) {
          try { body.migration = JSON.parse(this.migrationJson); }
          catch(e) { this.initMsg = 'JSON 格式错误'; this.working = false; return; }
        }
        const r = await fetch('/api/setup', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.success) { this.initialized = true; this.initMsg = ''; }
        else this.initMsg = d.message || '初始化失败';
      } catch(e) { this.initMsg = '初始化请求失败'; }
      finally { this.working = false; }
    },
    doLogout() { this.loggedIn = false; this.token = ''; this.instances = []; },

    // ---- api helpers ----
    async api(path, body) {
      const headers = {'Content-Type':'application/json'};
      if (this.token) headers['Authorization'] = 'Bearer ' + this.token;
      if (this.csrfToken) headers['X-CSRF-Token'] = this.csrfToken;
      const r = await fetch(path, {method:'POST',headers,body:body?JSON.stringify(body):undefined});
      return r.json();
    },

    // ---- instances ----
    async fetchInstances() {
      this.loading = true;
      try {
        const d = await this.api('/api/status');
        this.instances = (d.data || []).sort((a,b)=>(a.regionId+a.remark).localeCompare(b.regionId+b.remark));
      } catch(e) { this.toastMsg('获取实例失败','error'); }
      finally { this.loading = false; }
    },
    async doControl(id, action) {
      const d = await this.api('/api/control', { accountId: id, action, shutdownMode: this.cfg.shutdown_mode || 'KeepCharging' });
      if (!d.success) { this.toastMsg(d.message || '操作失败', 'error'); return; }
      this.toastMsg(action==='start'?'开机指令已提交':'停机指令已提交', 'success');
      setTimeout(() => this.fetchInstances(), 2000);
    },
    async confirmDelete(inst) {
      if (!confirm(\`确认释放 \${inst.remark||inst.instanceId}？此操作不可恢复。\`)) return;
      const d = await this.api('/api/delete', { accountId: inst.id });
      if (!d.success) { this.toastMsg(d.message || '操作失败', 'error'); return; }
      this.toastMsg('释放指令已提交','success');
      setTimeout(() => this.fetchInstances(), 2000);
    },

    // ---- config ----
    async fetchConfig() {
      try {
        const d = await this.api('/api/config');
        for (const k of Object.keys(this.cfg)) { if (d[k] !== undefined) this.cfg[k] = d[k]; }
        this.ddns.enabled = d.ddns_enabled || '0';
        this.ddns.domain = d.ddns_domain || '';
        this.ddns.zoneId = d.ddns_cf_zone_id || '';
        this.ddns.token = d.ddns_cf_token ? '********' : '';
        this.ddns.proxied = d.ddns_cf_proxied || '0';
      } catch(e) { /* ignore */ }
    },
    async saveSettings() {
      this.working = true;
      try {
        const body = { ...this.cfg };
        if (body.notify_password === '********') delete body.notify_password;
        const d = await this.api('/api/save-config', body);
        if (!d.success) { this.toastMsg(d.message || '保存失败', 'error'); return; }
        this.toastMsg('设置已保存','success');
      } catch(e) { this.toastMsg('保存失败','error'); }
      finally { this.working = false; }
    },
    async saveDdns() {
      this.working = true;
      try {
        const body = {
          ddns_enabled: this.ddns.enabled, ddns_domain: this.ddns.domain,
          ddns_cf_zone_id: this.ddns.zoneId, ddns_cf_proxied: this.ddns.proxied,
          ddns_provider: 'cloudflare',
        };
        if (this.ddns.token && this.ddns.token !== '********') body.ddns_cf_token = this.ddns.token;
        const d = await this.api('/api/save-config', body);
        if (!d.success) { this.toastMsg(d.message || '保存失败', 'error'); return; }
        this.toastMsg('DDNS 配置已保存','success');
      } catch(e) { this.toastMsg('保存失败','error'); }
      finally { this.working = false; }
    },

    // ---- logs ----
    async fetchLogs() {
      try {
        const d = await this.api('/api/logs', { tab: this.logTab });
        this.logs = d.data || [];
      } catch(e) { /* ignore */ }
    },
    async clearLogs() {
      if (!confirm('确认清空当前日志？')) return;
      const d = await this.api('/api/clear-logs', { tab: this.logTab });
      if (!d.success) { this.toastMsg('清空失败', 'error'); return; }
      this.logs = [];
      this.toastMsg('日志已清空','success');
    },

    // ---- actions ----
    async sendTestEmail() {
      this.working = true;
      try {
        const d = await this.api('/api/send-test-email');
        this.toastMsg(d.message || '已发送', d.success ? 'success' : 'error');
      } catch(e) { this.toastMsg('发送失败','error'); }
      finally { this.working = false; }
    },
    async sendTestWebhook() {
      this.working = true;
      try {
        const d = await this.api('/api/send-test-wh');
        this.toastMsg(d.message || '已发送', d.success ? 'success' : 'error');
      } catch(e) { this.toastMsg('发送失败','error'); }
      finally { this.working = false; }
    },
    async doExport() {
      this.working = true; this.exportResult = '';
      try {
        const d = await this.api('/api/export');
        if (d.success) {
          const blob = new Blob([JSON.stringify(d.data,null,2)],{type:'application/json'});
          const a = document.createElement('a'); a.href = URL.createObjectURL(blob);
          a.download = 'ecs-export-'+new Date().toISOString().slice(0,10)+'.json'; a.click();
          this.exportResult = '已下载';
        }
      } catch(e) { this.toastMsg('导出失败','error'); }
      finally { this.working = false; }
    },

    // ---- utils ----
    statusText(s) {
      const m = {Running:'运行中',Stopped:'已停止',Starting:'启动中',Stopping:'停机中',Pending:'创建中',Releasing:'释放中',Released:'已释放',Unknown:'未知'};
      return m[s] || s || '未知';
    },
    fmtTraffic(gb) { return gb<1 ? Math.round(gb*1024)+' MB' : parseFloat(gb.toFixed(1))+' GB'; },
    fmtTime(ts) { return new Date(ts*1000).toLocaleString('zh-CN'); },
    toastMsg(msg, type) {
      this.toast = {msg,type};
      setTimeout(()=>{this.toast=null},3000);
    },
  },

  watch: {
    tab(v) { if (v==='logs') this.fetchLogs(); },
    logTab() { this.fetchLogs(); },
  },
}).mount('#app');
</script>
</body></html>`;
}
