import { signedRequest } from './aliyun-sign';
import type { Account, EcsInstance } from './types';

function ak(account: { access_key_id: string; access_key_secret: string }) {
  return { AccessKeyId: account.access_key_id, AccessKeySecret: account.access_key_secret };
}

// === ECS ===
export async function getRegions(key: string, secret: string): Promise<{ regionId: string; localName: string }[]> {
  const r = await signedRequest({ ...ak({ access_key_id: key, access_key_secret: secret }), endpoint: 'ecs.cn-hangzhou.aliyuncs.com', action: 'DescribeRegions', version: '2014-05-26' });
  return ((r.Regions as any)?.Region ?? []).map((reg: any) => ({ regionId: reg.RegionId, localName: reg.LocalName ?? reg.RegionId }));
}

export async function getInstances(account: Account): Promise<EcsInstance[]> {
  const regions = await getRegions(account.access_key_id, account.access_key_secret);
  const target = regions.filter(r => r.regionId === account.region_id);
  const all: EcsInstance[] = [];

  for (const reg of (target.length ? target : regions)) {
    let page = 1;
    let total = 0;
    do {
      const r = await signedRequest({
        ...ak(account), endpoint: `ecs.${reg.regionId}.aliyuncs.com`,
        action: 'DescribeInstances', version: '2014-05-26',
        params: { RegionId: reg.regionId, PageSize: 100, PageNumber: page },
      });
      const items = (r.Instances as any)?.Instance ?? [];
      for (const inst of items) {
        all.push({
          instanceId: inst.InstanceId ?? '', instanceName: inst.InstanceName ?? '',
          status: inst.Status ?? 'Unknown', regionId: reg.regionId, regionName: reg.localName,
          instanceType: inst.InstanceType ?? '', cpu: inst.Cpu ?? 0, memory: inst.Memory ?? 0,
          internetMaxBandwidthOut: parseInt(inst.EipAddress?.Bandwidth ?? inst.InternetMaxBandwidthOut ?? 0),
          osName: inst.OSName ?? '',
          publicIp: inst.PublicIpAddress?.IpAddress?.[0] ?? inst.EipAddress?.IpAddress ?? '',
          eipAllocationId: inst.EipAddress?.AllocationId ?? '',
          eipAddress: inst.EipAddress?.IpAddress ?? '',
          privateIp: inst.VpcAttributes?.PrivateIpAddress?.IpAddress?.[0] ?? '',
          stoppedMode: inst.StoppedMode ?? '', chargeType: inst.InstanceChargeType ?? '',
        });
      }
      total = parseInt(String(r.TotalCount ?? items.length));
      page++;
    } while (total > 0 && (page - 1) * 100 < total);
  }
  return all;
}

export async function getInstanceStatus(account: Account): Promise<string> {
  if (!account.instance_id) return 'Unknown';
  const r = await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: 'DescribeInstanceStatus', version: '2014-05-26',
    params: { RegionId: account.region_id, 'InstanceId.1': account.instance_id },
  });
  const statuses = (r.InstanceStatuses as any)?.InstanceStatus ?? [];
  const match = statuses.find((s: any) => s.InstanceId === account.instance_id);
  return match?.Status ?? 'Unknown';
}

export async function controlInstance(account: Account, action: 'start' | 'stop', shutdownMode = 'KeepCharging'): Promise<void> {
  // 写操作不重试（maxAttempts=1）：请求可能已达服务端，重试会重复执行停机/开机
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: action === 'stop' ? 'StopInstance' : 'StartInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, ...(action === 'stop' ? { StoppedMode: shutdownMode } : {}) },
  }, 1);
}

export async function deleteInstance(account: Account): Promise<void> {
  // 写操作不重试：避免重复删除
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: 'DeleteInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, Force: 'true' },
  }, 1);
}

// === CDT ===
export async function getTraffic(account: Account): Promise<number> {
  const r = await signedRequest({
    ...ak(account), endpoint: 'cdt.aliyuncs.com',
    action: 'ListCdtInternetTraffic', version: '2021-08-13',
  });
  const details = (r.TrafficDetails as any[]) ?? [];
  const isOverseas = !account.region_id.startsWith('cn-') || account.region_id === 'cn-hongkong';
  let total = 0;
  for (const d of details) {
    const reg = d.BusinessRegionId ?? '';
    const overseas = !reg.startsWith('cn-') || reg === 'cn-hongkong';
    if (overseas === isOverseas) total += (d.Traffic ?? 0);
  }
  return total / (1024 * 1024 * 1024);
}

// === BSS ===
export async function getAccountBalance(account: Account): Promise<{ AvailableAmount: string; Currency: string }> {
  const bssRegion = account.site_type === 'international' ? 'ap-southeast-1' : 'cn-hangzhou';
  const r = await signedRequest({
    ...ak(account), endpoint: `business.${bssRegion}.aliyuncs.com`,
    action: 'QueryAccountBalance', version: '2017-12-14',
  });
  return { AvailableAmount: (r.Data as any)?.AvailableAmount ?? '0', Currency: (r.Data as any)?.Currency ?? 'CNY' };
}

export async function getBillOverview(account: Account, billingCycle: string): Promise<{ TotalCost: number }> {
  const bssRegion = account.site_type === 'international' ? 'ap-southeast-1' : 'cn-hangzhou';
  const r = await signedRequest({
    ...ak(account), endpoint: `business.${bssRegion}.aliyuncs.com`,
    action: 'QueryBillOverview', version: '2017-12-14',
    params: { BillingCycle: billingCycle },
  });
  const items = (r.Data as any)?.Items?.Item ?? [];
  let cost = 0;
  for (const item of items) cost += parseFloat(item.PretaxAmount ?? 0);
  return { TotalCost: Math.round(cost * 100) / 100 };
}

export async function getInstanceBill(account: Account, billingCycle: string): Promise<{ TotalCost: number }> {
  if (!account.instance_id) return { TotalCost: 0 };
  const bssRegion = account.site_type === 'international' ? 'ap-southeast-1' : 'cn-hangzhou';
  const r = await signedRequest({
    ...ak(account), endpoint: `business.${bssRegion}.aliyuncs.com`,
    action: 'DescribeInstanceBill', version: '2017-12-14',
    params: { BillingCycle: billingCycle, InstanceID: account.instance_id, Granularity: 'MONTHLY' },
  });
  const items = (r.Data as any)?.Items ?? [];
  let cost = 0;
  for (const item of items) cost += parseFloat(item.PretaxAmount ?? 0);
  return { TotalCost: Math.round(cost * 100) / 100 };
}

// === CMS (CloudMonitor) ===
// Reserved for per-instance traffic tracking. Currently CDT getTraffic() covers total account traffic.
export async function getInstanceOutboundBytes(account: Account, startMs: number, endMs: number): Promise<number> {
  let total = 0;
  let cursor = startMs;
  const period = 60;
  const chunkMs = 24 * 60 * 60 * 1000;
  while (cursor < endMs) {
    const chunkEnd = Math.min(cursor + chunkMs, endMs);
    let nextToken: string | undefined;
    do {
      const params: Record<string, string | number> = {
        Namespace: 'acs_ecs_dashboard', MetricName: 'InternetOutRate',
        Period: period, StartTime: cursor, EndTime: chunkEnd,
        Dimensions: JSON.stringify([{ instanceId: account.instance_id }]),
        Length: 1440,
      };
      if (nextToken) params.NextToken = nextToken;
      const r = await signedRequest({
        ...ak(account), endpoint: 'metrics.aliyuncs.com',
        action: 'DescribeMetricList', version: '2019-01-01', params,
      });
      const dps = typeof r.Datapoints === 'string' ? JSON.parse(r.Datapoints as string) : (r.Datapoints ?? []);
      for (const p of (dps as any[])) {
        const ts = parseInt(p.timestamp ?? 0);
        if (ts <= startMs || ts > endMs) continue;
        const rate = Math.max(0, parseFloat(p.Average ?? p.Maximum ?? 0));
        total += (rate * period) / 8;
      }
      nextToken = r.NextToken as string | undefined;
    } while (nextToken);
    cursor = chunkEnd;
  }
  return total;
}

// === EIP (VPC 2016-04-28) ===

export interface EipInfo { allocationId: string; ipAddress: string; status: string }

const VPC_VERSION = '2016-04-28';
function vpcEndpoint(regionId: string): string { return `vpc.${regionId}.aliyuncs.com`; }

/** 查询 EIP（AllocationId / IpAddress / EipName 等任一条件） */
export async function describeEipAddresses(account: Account, params: Record<string, string>): Promise<EipInfo[]> {
  const r = await signedRequest({
    ...ak(account), endpoint: vpcEndpoint(account.region_id), action: 'DescribeEipAddresses', version: VPC_VERSION,
    params: { RegionId: account.region_id, ...params },
  });
  const list = (r.EipAddresses as any)?.EipAddress ?? [];
  return (list as any[]).map(e => ({
    allocationId: String(e.AllocationId ?? ''), ipAddress: String(e.IpAddress ?? ''), status: String(e.Status ?? ''),
  }));
}

/** 申请按量付费 EIP（写操作不重试：重复申请会多计费） */
export async function allocateEipAddress(account: Account, bandwidth: number, name: string): Promise<EipInfo> {
  const r = await signedRequest({
    ...ak(account), endpoint: vpcEndpoint(account.region_id), action: 'AllocateEipAddress', version: VPC_VERSION,
    params: {
      RegionId: account.region_id, Bandwidth: Math.max(1, Math.floor(bandwidth)),
      InternetChargeType: 'PayByTraffic', Name: `${name}-eip`,
      'Tag.1.Key': 'ecs-control-managed', 'Tag.1.Value': 'true',
    },
  }, 1);
  const allocationId = String(r.AllocationId ?? '');
  if (!allocationId) throw new Error('EIP 申请成功但未返回 AllocationId');
  return { allocationId, ipAddress: String(r.EipAddress ?? ''), status: 'Available' };
}

export async function associateEipAddress(account: Account, allocationId: string, instanceId: string): Promise<void> {
  if (!allocationId || !instanceId) throw new Error('EIP 绑定参数缺失');
  await signedRequest({
    ...ak(account), endpoint: vpcEndpoint(account.region_id), action: 'AssociateEipAddress', version: VPC_VERSION,
    params: { RegionId: account.region_id, AllocationId: allocationId, InstanceId: instanceId, InstanceType: 'EcsInstance' },
  }, 1);
}

/** 解绑 EIP；IncorrectEipStatus / InvalidAllocationId.NotFound 视为已解绑（幂等，对齐 PHP 实现） */
export async function unassociateEipAddress(account: Account, allocationId: string, instanceId: string): Promise<void> {
  if (!allocationId) return;
  const params: Record<string, string> = { RegionId: account.region_id, AllocationId: allocationId, InstanceType: 'EcsInstance' };
  if (instanceId) params.InstanceId = instanceId;
  try {
    await signedRequest({
      ...ak(account), endpoint: vpcEndpoint(account.region_id), action: 'UnassociateEipAddress', version: VPC_VERSION, params,
    }, 1);
  } catch (e: any) {
    const msg = String(e?.message ?? '');
    if (msg.includes('IncorrectEipStatus') || msg.includes('InvalidAllocationId.NotFound')) return;
    throw e;
  }
}

export async function releaseEipAddress(account: Account, allocationId: string): Promise<void> {
  if (!allocationId) return;
  await signedRequest({
    ...ak(account), endpoint: vpcEndpoint(account.region_id), action: 'ReleaseEipAddress', version: VPC_VERSION,
    params: { RegionId: account.region_id, AllocationId: allocationId },
  }, 1);
}

/** 清理失败不掩盖原始异常（对齐 PHP releaseEipAddressSilently） */
export async function releaseEipAddressSilently(account: Account, allocationId: string): Promise<void> {
  try { await releaseEipAddress(account, allocationId); } catch { /* ignore */ }
}

/** 轮询 EIP 状态直到期望值；返回命中的详情或 null */
export async function waitEipStatus(account: Account, allocationId: string, expected: string, tries: number): Promise<EipInfo | null> {
  for (let i = 0; i < tries; i++) {
    const rows = await describeEipAddresses(account, { AllocationId: allocationId }).catch(() => [] as EipInfo[]);
    const hit = rows.find(r => r.allocationId === allocationId);
    if (hit && hit.status === expected) return hit;
    await new Promise(r => setTimeout(r, 2000));
  }
  return null;
}

/**
 * 更换系统托管 EIP：申请新 EIP → 解绑旧 → 等 Available → 绑定新 → 等 InUse → 释放旧。
 * 中途失败会释放已申请的新 EIP，避免产生闲置计费（对齐 PHP AliyunService::replaceManagedEip）。
 */
export async function replaceManagedEip(account: Account): Promise<EipInfo> {
  if (account.public_ip_mode !== 'eip' || !account.eip_managed || !account.eip_allocation_id) {
    throw new Error('当前实例不是系统托管 EIP，无法更换公网 IP');
  }
  const bandwidth = Math.max(1, Number(account.internet_max_bandwidth_out || 100));
  const name = account.instance_name || account.instance_id;
  const newEip = await allocateEipAddress(account, bandwidth, `${name}-replace`);
  try {
    await unassociateEipAddress(account, account.eip_allocation_id, account.instance_id);
    await waitEipStatus(account, account.eip_allocation_id, 'Available', 8);
    await associateEipAddress(account, newEip.allocationId, account.instance_id);
    await waitEipStatus(account, newEip.allocationId, 'InUse', 12);
    await releaseEipAddress(account, account.eip_allocation_id);
  } catch (e) {
    await releaseEipAddressSilently(account, newEip.allocationId);
    throw e;
  }
  let ip = newEip.ipAddress;
  if (!ip) {
    const detail = await waitEipStatus(account, newEip.allocationId, 'InUse', 6);
    ip = detail?.ipAddress ?? '';
  }
  return { allocationId: newEip.allocationId, ipAddress: ip, status: 'InUse' };
}
