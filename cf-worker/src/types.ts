// === Account row ===
export interface Account {
  id: number; access_key_id: string; access_key_secret: string;
  region_id: string; instance_id: string; max_traffic: number;
  traffic_used: number; traffic_billing_month: string;
  instance_status: string; health_status: string; stopped_mode: string;
  updated_at: number; last_keep_alive_at: number; is_deleted: number;
  auto_start_blocked: number;
  schedule_enabled: number; schedule_start_enabled: number; schedule_stop_enabled: number;
  start_time: string; stop_time: string;
  schedule_last_start_date: string; schedule_last_stop_date: string;
  schedule_blocked_by_traffic: number;
  remark: string; site_type: string; group_key: string;
  instance_name: string; instance_type: string;
  internet_max_bandwidth_out: number;
  public_ip: string; public_ip_mode: string;
  eip_allocation_id: string; eip_address: string; eip_managed: number;
  private_ip: string; cpu: number; memory: number; os_name: string;
  traffic_api_status: string; traffic_api_message: string;
  protection_suspended: number; protection_suspend_reason: string;
  protection_suspend_notified_at: number;
}

// === Account Group (from settings.account_groups JSON) ===
export interface AccountGroup {
  groupKey: string; AccessKeyId: string; AccessKeySecret: string;
  regionId: string; siteType: string; maxTraffic: number; remark: string;
  scheduleEnabled: boolean; scheduleStartEnabled: boolean; scheduleStopEnabled: boolean;
  startTime: string; stopTime: string; scheduleBlockedByTraffic?: boolean;
}

// === API ===
export interface TrafficResult {
  success: boolean; value: number | null; status: string; message: string;
}

export interface EcsInstance {
  instanceId: string; instanceName: string; status: string;
  regionId: string; regionName: string; instanceType: string;
  cpu: number; memory: number; internetMaxBandwidthOut: number;
  osName: string; publicIp: string; eipAllocationId: string;
  eipAddress: string; privateIp: string; stoppedMode: string; chargeType: string;
}

export interface EcsCreatePreview {
  account: { groupKey: string; label: string };
  regionId: string; zoneId: string; instanceType: string; instanceName: string;
  osKey: string; osLabel: string; imageId: string; imageSize: number;
  loginUser: string; loginPort: number;
  internetMaxBandwidthOut: number; publicIpMode: string;
  systemDisk: { category: string; size: number; min: number; max: number; unit: string };
  network: {
    vpc: { mode: string; name: string; cidr: string };
    vswitch: { mode: string; name: string; cidr: string };
    securityGroup: { mode: string; name: string; rules: string[] };
  };
  pricing: { available: boolean; currency: string; message: string };
  warnings: string[];
}

export interface InstanceSnapshot {
  id: number; accountId: number; groupKey: string;
  account: string; accountLabel: string;
  flow_total: number; flow_used: number; percentageOfUse: number;
  trafficStatus: string; trafficMessage: string;
  region: string; regionId: string; regionName: string;
  rate95: boolean; threshold: number;
  instanceStatus: string; status: string; healthStatus: string;
  stoppedMode: string; cpu: number; memory: number;
  lastUpdated: string; remark: string;
  instanceId: string; instanceName: string; instanceType: string;
  osName: string; internetMaxBandwidthOut: number;
  publicIp: string; publicIpMode: string;
  eipAllocationId: string; eipAddress: string; eipManaged: boolean;
  privateIp: string; maxTraffic: number; siteType: string;
  cost?: Record<string, unknown>;
  operationLocked?: boolean; operationLockedReason?: string;
}

// === JWT ===
export interface JwtPayload {
  role: 'admin'; csrf_token: string; iat: number; exp: number;
}

// === Env ===
export interface Env {
  DB: D1Database;
  ENCRYPTION_KEY: string; JWT_SECRET: string;
}

// === Migration ===
export interface MigrationExport {
  version: number; exported_at: string;
  settings: Record<string, unknown>;
  notification: Record<string, unknown>;
  ddns: Record<string, unknown>;
  accounts: Record<string, unknown>[];
  account_groups: Record<string, unknown>[];
}
