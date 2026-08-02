<?php

use AlibabaCloud\Client\AlibabaCloud;

class BssService
{
    private $balanceCache = [];

    private function getBssEndpoint($siteType = 'china')
    {
        if ($siteType === 'international') {
            return ['regionId' => 'ap-southeast-1', 'host' => 'business.ap-southeast-1.aliyuncs.com'];
        }
        return ['regionId' => 'cn-hangzhou', 'host' => 'business.aliyuncs.com'];
    }

    public function getAccountBalance($key, $secret, $siteType = 'china')
    {
        $cacheKey = md5($key . '|' . $siteType);
        if (isset($this->balanceCache[$cacheKey])) return $this->balanceCache[$cacheKey];

        $bss = $this->getBssEndpoint($siteType);

        $result = RetryHandler::execute(function () use ($key, $secret, $bss) {
            AlibabaCloud::accessKeyClient($key, $secret)->regionId($bss['regionId'])->asDefaultClient();
            return AlibabaCloud::rpc()->product('BssOpenApi')->scheme('https')->version('2017-12-14')
                ->action('QueryAccountBalance')->method('POST')->host($bss['host'])
                ->options(['connect_timeout' => 5.0, 'timeout' => 10.0])
                ->request();
        }, 'getAccountBalance');

        $data = ['AvailableAmount' => $result['Data']['AvailableAmount'] ?? '0', 'Currency' => $result['Data']['Currency'] ?? 'CNY'];
        $this->balanceCache[$cacheKey] = $data;
        return $data;
    }

    public function getInstanceBill($key, $secret, $instanceId, $billingCycle, $siteType = 'china')
    {
        $bss = $this->getBssEndpoint($siteType);
        $totalCost = 0; $details = []; $nextToken = ''; $page = 0;
        do {
            // 分页保护:MaxResults 上限 300,最多 50 页,防止服务端异常导致死循环
            if (++$page > 50) break;
            $query = ['BillingCycle' => $billingCycle, 'InstanceID' => $instanceId, 'Granularity' => 'MONTHLY', 'MaxResults' => 300];
            if ($nextToken !== '') $query['NextToken'] = $nextToken;
            $result = RetryHandler::execute(function () use ($key, $secret, $bss, $query) {
                AlibabaCloud::accessKeyClient($key, $secret)->regionId($bss['regionId'])->asDefaultClient();
                return AlibabaCloud::rpc()->product('BssOpenApi')->scheme('https')->version('2017-12-14')
                    ->action('DescribeInstanceBill')->method('POST')->host($bss['host'])
                    ->options(['query' => $query, 'connect_timeout' => 5.0, 'timeout' => 15.0])
                    ->request();
            }, 'getInstanceBill');

            $data = $result['Data'] ?? [];
            foreach (($data['Items'] ?? []) as $item) {
                $cost = (float) ($item['PretaxAmount'] ?? 0); $totalCost += $cost;
                $details[] = ['ProductName' => $item['ProductName'] ?? '', 'ProductCode' => $item['ProductCode'] ?? '',
                    'BillingType' => $item['BillingType'] ?? '', 'PretaxAmount' => $cost,
                    'DeductedByCashCoupons' => (float) ($item['DeductedByCashCoupons'] ?? 0),
                    'DeductedByPrepaidCard' => (float) ($item['DeductedByPrepaidCard'] ?? 0),
                    'PaymentAmount' => (float) ($item['PaymentAmount'] ?? 0)];
            }
            $nextToken = (string) ($data['NextToken'] ?? '');
        } while ($nextToken !== '');
        return ['TotalCost' => round($totalCost, 2), 'Items' => $details];
    }

    public function getBillOverview($key, $secret, $billingCycle, $siteType = 'china')
    {
        $bss = $this->getBssEndpoint($siteType);
        $totalCost = 0; $products = []; $pageNum = 1; $totalPages = 1;
        do {
            // 分页保护:PageSize 上限 100,最多 50 页
            if ($pageNum > 50) break;
            $result = RetryHandler::execute(function () use ($key, $secret, $bss, $billingCycle, $pageNum) {
                AlibabaCloud::accessKeyClient($key, $secret)->regionId($bss['regionId'])->asDefaultClient();
                return AlibabaCloud::rpc()->product('BssOpenApi')->scheme('https')->version('2017-12-14')
                    ->action('QueryBillOverview')->method('POST')->host($bss['host'])
                    ->options(['query' => ['BillingCycle' => $billingCycle, 'PageNum' => $pageNum, 'PageSize' => 100], 'connect_timeout' => 5.0, 'timeout' => 15.0])
                    ->request();
            }, 'getBillOverview');

            $data = $result['Data'] ?? [];
            foreach (($data['Items']['Item'] ?? []) as $item) {
                $cost = (float) ($item['PretaxAmount'] ?? 0); if ($cost <= 0) continue;
                $totalCost += $cost;
                $products[] = ['ProductName' => $item['ProductName'] ?? '', 'ProductCode' => $item['ProductCode'] ?? '',
                    'PretaxAmount' => round($cost, 2), 'PaymentAmount' => round((float) ($item['PaymentAmount'] ?? 0), 2)];
            }
            $totalCount = (int) ($data['TotalCount'] ?? 0);
            $pageSize = (int) ($data['PageSize'] ?? 100);
            $totalPages = $pageSize > 0 ? (int) ceil($totalCount / $pageSize) : 1;
            $pageNum++;
        } while ($pageNum <= $totalPages);
        usort($products, fn($a, $b) => $b['PretaxAmount'] <=> $a['PretaxAmount']);
        return ['TotalCost' => round($totalCost, 2), 'Products' => $products];
    }
}
