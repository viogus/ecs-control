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
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: action === 'stop' ? 'StopInstance' : 'StartInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, ...(action === 'stop' ? { StoppedMode: shutdownMode } : {}) },
  });
}

export async function deleteInstance(account: Account): Promise<void> {
  await signedRequest({
    ...ak(account), endpoint: `ecs.${account.region_id}.aliyuncs.com`,
    action: 'DeleteInstance', version: '2014-05-26',
    params: { RegionId: account.region_id, InstanceId: account.instance_id, Force: 'true' },
  });
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
