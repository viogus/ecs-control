import { signedRequest } from './aliyun-sign';
import type { Account, EcsCreatePreview } from './types';

const TAG_KEY = 'ecs-control-managed';
const TAG_VAL = 'true';

export async function buildPreview(account: Account, regionId: string, instanceType = 'ecs.e-c4m1.large', osKey = 'debian_12', publicIpMode: 'ecs_public_ip' | 'eip' = 'ecs_public_ip'): Promise<EcsCreatePreview> {
  const a = { AccessKeyId: account.access_key_id, AccessKeySecret: account.access_key_secret };

  // Get zones
  const zonesR = await signedRequest({ ...a, endpoint: `ecs.${regionId}.aliyuncs.com`, action: 'DescribeZones', version: '2014-05-26', params: { RegionId: regionId, InstanceType: instanceType } });
  const zones = (zonesR.Zones as any)?.Zone ?? [];
  const zoneId = zones[0]?.ZoneId ?? '';
  if (!zoneId) throw new Error(`No available zone for ${instanceType} in ${regionId}`);

  // Get images (Debian 12)
  const imgR = await signedRequest({ ...a, endpoint: `ecs.${regionId}.aliyuncs.com`, action: 'DescribeImages', version: '2014-05-26', params: { RegionId: regionId, ImageOwnerAlias: 'system', OSType: 'linux', Platform: 'Debian', Status: 'Available', PageSize: 100 } });
  const images = (imgR.Images as any)?.Image ?? [];
  images.sort((a: any, b: any) => String(b.CreationTime ?? '').localeCompare(String(a.CreationTime ?? '')));
  const image = images.find((i: any) => String(i.ImageName ?? '').toLowerCase().includes('debian 12')) ?? images[0];
  if (!image) throw new Error('No Debian 12 image found');

  const diskSize = 20;
  const bandwidth = 200;

  return {
    account: { groupKey: account.group_key, label: account.remark || account.instance_name || '' },
    regionId, zoneId, instanceType,
    instanceName: `launch-${new Date().toISOString().replace(/[-:T]/g, '').substring(0, 12)}`,
    osKey, osLabel: 'Debian 12', imageId: image.ImageId, imageSize: image.Size ?? 0,
    loginUser: 'root', loginPort: 22,
    internetMaxBandwidthOut: bandwidth, publicIpMode,
    systemDisk: { category: 'cloud_essd_entry', size: diskSize, min: 20, max: 2048, unit: 'GiB' },
    network: {
      vpc: { mode: 'auto', name: `ecs-ctrl-vpc-${regionId}`, cidr: '172.31.0.0/16' },
      vswitch: { mode: 'auto', name: `ecs-ctrl-vsw-${zoneId}`, cidr: `172.31.1.0/24` },
      securityGroup: { mode: 'auto', name: `ecs-ctrl-sg-${regionId}`, rules: ['0.0.0.0/0'] },
    },
    pricing: { available: false, currency: 'CNY', message: 'Pay-as-you-go billing. Final charges per Alibaba Cloud bill.' },
    warnings: ['Security group allows all inbound. Restrict in production.'],
  };
}
