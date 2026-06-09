;window.aliyunRegions = [
    { id: 'cn-hongkong', name: '中国香港' },
    { id: 'ap-southeast-1', name: '新加坡' },
    { id: 'ap-northeast-1', name: '日本（东京）' },
    { id: 'ap-northeast-2', name: '韩国（首尔）' },
    { id: 'us-west-1', name: '美国（硅谷）' },
    { id: 'us-east-1', name: '美国（弗吉尼亚）' },
    { id: 'eu-central-1', name: '德国（法兰克福）' },
    { id: 'eu-west-1', name: '英国（伦敦）' },
    { id: 'ap-southeast-2', name: '澳大利亚（悉尼）' },
    { id: 'ap-southeast-3', name: '马来西亚（吉隆坡）' },
    { id: 'ap-southeast-5', name: '印度尼西亚（雅加达）' },
    { id: 'ap-southeast-6', name: '菲律宾（马尼拉）' },
    { id: 'ap-southeast-7', name: '泰国（曼谷）' },
    { id: 'me-east-1', name: '阿联酋（迪拜）' },
    { id: 'cn-hangzhou', name: '华东 1（杭州）' },
    { id: 'cn-shanghai', name: '华东 2（上海）' },
    { id: 'cn-qingdao', name: '华北 1（青岛）' },
    { id: 'cn-beijing', name: '华北 2（北京）' },
    { id: 'cn-zhangjiakou', name: '华北 3（张家口）' },
    { id: 'cn-huhehaote', name: '华北 5（呼和浩特）' },
    { id: 'cn-wulanchabu', name: '华北 6（乌兰察布）' },
    { id: 'cn-shenzhen', name: '华南 1（深圳）' },
    { id: 'cn-heyuan', name: '华南 2（河源）' },
    { id: 'cn-guangzhou', name: '华南 3（广州）' },
    { id: 'cn-chengdu', name: '西南 1（成都）' }
];

window.createDefaultAccount = () => ({
    groupKey: '',
    AccessKeyId: '',
    AccessKeySecret: '',
    regionId: '',
    siteType: 'international',
    maxTraffic: 200,
    remark: '',
    scheduleEnabled: false,
    scheduleStartEnabled: false,
    scheduleStopEnabled: false,
    startTime: '',
    stopTime: '',
    scheduleBlockedByTraffic: false,
    usageUsed: 0,
    usageRemaining: 200,
    usagePercent: 0,
    instanceCount: 0,
    usageLastUpdated: '',
    trafficStatus: 'ok',
    trafficMessage: ''
});

window.createDefaultConfig = () => ({
    admin_password: '',
    traffic_threshold: 95, cost_threshold: 0.48, cost_threshold_enabled: false,
    shutdown_mode: 'KeepCharging', tz_offset_hours: '8',
    threshold_action: 'stop_and_notify',
    keep_alive: false,
    monthly_auto_start: false,
    api_interval: 600,
    enable_billing: false,
    AppBrand: {
        logo_url: ''
    },
    Ddns: {
        enabled: false,
        provider: 'cloudflare',
        domain: '',
        cloudflare: {
            zone_id: '',
            token: '',
            proxied: false
        }
    },
    Notification: {
        email_enabled: true,
        email: '',
        host: '',
        port: 465,
        username: '',
        password: '',
        secure: 'ssl',
        telegram: {
            enabled: false,
            token: '',
            chat_id: '',
            proxy_type: 'none',
            proxy_url: '',
            proxy_ip: '',
            proxy_port: '',
            proxy_user: '',
            proxy_pass: '',
            allowed_user_ids: '',
            confirm_ttl: 60
        },
        webhook: {
            enabled: false,
            url: '',
            method: 'GET',
            request_type: 'JSON',
            headers: '',
            body: ''
        }
    },
    Accounts: []
});

window.ecsOsOptions = [
    { value: 'debian_12', label: 'Debian 12' },
    { value: 'alibaba_cloud_linux_3', label: 'Alibaba Cloud Linux 3' },
    { value: 'ubuntu_22', label: 'Ubuntu 22.04' },
    { value: 'ubuntu_24', label: 'Ubuntu 24.04' },
    { value: 'centos_stream_9', label: 'CentOS Stream 9' },
    { value: 'windows_2022', label: 'Windows Server 2022' }
];

window.createDefaultEcsDraft = () => ({
    accountGroupKey: '',
    regionId: '',
    instanceType: 'ecs.e-c4m1.large',
    osKey: 'debian_12',
    systemDiskSize: 20,
    systemDiskCategory: 'cloud_essd_entry',
    publicIpMode: 'ecs_public_ip',
    instanceName: `launch-${new Date().toISOString().slice(0, 19).replace(/[-:T]/g, '').slice(0, 12)}`
});
