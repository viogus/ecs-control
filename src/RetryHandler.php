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
                $errorCode = $e->getErrorCode();
                if (stripos($errorCode, 'Throttling') !== false) {
                    $lastException = $e;
                    self::backoff($attempt, true);
                    $attempt++;
                    continue;
                }
                throw $e;
            } catch (ServerException $e) {
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
        $base = 1000000 * pow(2, $attempt);
        if ($isThrottling) {
            $base *= 2;
        }
        $jitter = rand(0, 500000);
        usleep($base + $jitter);
    }
}
