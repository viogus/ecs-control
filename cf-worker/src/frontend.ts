export function renderHtml(csrfToken: string): string {
  return `<!DOCTYPE html>
<html lang="zh">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ECS 服务器管家</title>
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js"></script>
<style>
*{margin:0;padding:0;box-sizing:border-box}
body{font-family:-apple-system,system-ui,sans-serif;background:#f5f5f7;color:#1d1d1f}
.container{max-width:960px;margin:0 auto;padding:20px}
.card{background:#fff;border-radius:16px;padding:24px;margin-bottom:16px;box-shadow:0 1px 3px rgba(0,0,0,.08)}
h1{font-size:24px;margin-bottom:16px}
.btn{padding:8px 16px;border-radius:8px;border:none;cursor:pointer;font-size:14px}
.btn-primary{background:#007aff;color:#fff}
.btn-danger{background:#ff3b30;color:#fff}
input,select{padding:8px 12px;border:1px solid #d2d2d7;border-radius:8px;font-size:14px;width:100%}
label{font-size:13px;color:#86868b;display:block;margin-bottom:4px}
.form-group{margin-bottom:12px}
.status-badge{padding:2px 8px;border-radius:12px;font-size:12px}
.status-Running{background:#34c759;color:#fff}
.status-Stopped{background:#ff3b30;color:#fff}
.status-Stopping,.status-Starting{background:#ff9500;color:#fff}
</style>
</head>
<body>
<div id="app">
<div class="container">
  <!-- Login -->
  <div v-if="!loggedIn" class="card">
    <h1>ECS 服务器管家</h1>
    <div v-if="!initialized">
      <div class="form-group"><label>管理员密码</label><input v-model="setupPassword" type="password"></div>
      <div class="form-group"><label>迁移数据 (可选，粘贴 Docker export JSON)</label><textarea v-model="migrationJson" rows="4"></textarea></div>
      <button class="btn btn-primary" @click="doSetup">初始化</button>
      <p v-if="initMsg" style="margin-top:8px;color:#ff3b30">{{ initMsg }}</p>
    </div>
    <div v-else>
      <div class="form-group"><label>密码</label><input v-model="loginPassword" type="password" @keyup.enter="doLogin"></div>
      <button class="btn btn-primary" @click="doLogin">登录</button>
      <p v-if="loginMsg" style="margin-top:8px;color:#ff3b30">{{ loginMsg }}</p>
    </div>
  </div>
  <!-- Loading / placeholder -->
  <div v-else>
    <p>已登录，管理面板加载中...</p>
  </div>
</div>
</div>
<script>
const { createApp } = Vue;
createApp({
  data() { return {
    loggedIn: false, initialized: false,
    loginPassword: '', setupPassword: '', migrationJson: '',
    loginMsg: '', initMsg: '',
    token: '', csrfToken: '${csrfToken}'
  };},
  async mounted() {
    try {
      const r = await fetch('/api/check-init', {method:'POST'});
      const d = await r.json();
      this.initialized = d.initialized;
    } catch(e) { this.initMsg = '无法连接到服务器'; }
  },
  methods: {
    async doLogin() {
      try {
        const r = await fetch('/api/login', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({password:this.loginPassword})});
        const d = await r.json();
        if (d.success) { this.token = d.token; this.csrfToken = d.csrf_token; this.loggedIn = true; }
        else this.loginMsg = d.message || '密码错误';
      } catch(e) { this.loginMsg = '登录请求失败'; }
    },
    async doSetup() {
      try {
        const body = { password: this.setupPassword };
        if (this.migrationJson.trim()) {
          try { body.migration = JSON.parse(this.migrationJson); }
          catch(e) { this.initMsg = 'JSON 格式错误'; return; }
        }
        const r = await fetch('/api/setup', {method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify(body)});
        const d = await r.json();
        if (d.success) { this.initialized = true; this.initMsg = ''; }
        else this.initMsg = d.message || '初始化失败';
      } catch(e) { this.initMsg = '初始化请求失败'; }
    }
  }
}).mount('#app');
</script>
</body></html>`;
}
