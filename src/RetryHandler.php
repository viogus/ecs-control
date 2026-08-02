<?php

use AlibabaCloud\Client\Exception\ClientException;
use AlibabaCloud\Client\Exception\ServerException;

class RetryHandler
{
    public static function execute(callable $func, string $action, int $maxRetries = 3): mixed
    {
        $attempt = 0;
        $lastException = null;

        while ($attempt < $maxRetries) {
            try {
                return $func();
            } catch (ClientException $e) {
                $errorCode = (string) $e->getErrorCode();
                if (stripos($errorCode, 'Throttling') !== false) {
                    $lastException = $e;
                    self::backoff($attempt, true);
                    $attempt++;
                    continue;
                }
                // 网络/客户端错误(连接超时、DNS 失败等)不重试:请求可能已到服务端,
                // 对非幂等写操作重试有重复执行风险,交由上层决定。
                throw $e;
            } catch (ServerException $e) {
                $httpStatus = 0;
                if (method_exists($e, 'getHttpStatus')) {
                    $httpStatus = (int) $e->getHttpStatus();
                }
                $errorCode = (string) $e->getErrorCode();
                // 仅重试服务端错误(5xx)与限流;业务类 4xx(InvalidParameter/OperationDenied 等)
                // 重试无意义且浪费配额,直接抛出。
                $retryable = $httpStatus >= 500
                    || stripos($errorCode, 'Throttling') !== false
                    || stripos($errorCode, 'ServiceUnavailable') !== false
                    || stripos($errorCode, 'InternalError') !== false;
                if (!$retryable) {
                    throw $e;
                }
                $lastException = $e;
            } catch (\Exception $e) {
                $lastException = $e;
            }

            $attempt++;
            if ($attempt < $maxRetries) {
                self::backoff($attempt);
            }
        }

        throw $lastException;
    }

    private static function backoff(int $attempt, bool $isThrottling = false): void
    {
        // 指数退避,上限 8 秒,避免长时间阻塞监控主循环
        $base = min((int) (1000000 * pow(2, min($attempt, 3))), 8000000);
        if ($isThrottling) {
            $base = min($base * 2, 8000000);
        }
        $jitter = rand(0, 500000);
        usleep($base + $jitter);
    }
}
