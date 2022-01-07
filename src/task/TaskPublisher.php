<?php

namespace phpboot\task;

use Carbon\Carbon;
use DateTimeZone;
use phpboot\caching\Cache;
use phpboot\common\AppConf;
use phpboot\common\constant\DateTimeFormat;
use phpboot\common\constant\TimeUnit;
use phpboot\common\util\ExceptionUtils;
use phpboot\common\util\JsonUtils;
use phpboot\common\util\StringUtils;
use phpboot\dal\redis\RedisCmd;
use phpboot\logging\Log;
use Throwable;

final class TaskPublisher
{
    /**
     * @var string
     */
    private static $_normalQueueCacheKey = 'redismq.normal';

    private static $_delayableQueueCacheKey = 'redismq.delayable';

    private function __construct()
    {
    }

    public static function normalQueueCacheKey(?string $key = null): string
    {
        if (is_string($key)) {
            if ($key !== '') {
                self::$_normalQueueCacheKey = $key;
            }

            return '';
        }

        $prefix = rtrim(Cache::cacheKeyPrefix());
        $cacheKey = self::$_normalQueueCacheKey;

        if ($prefix === '') {
            return $cacheKey;
        }

        return $prefix . StringUtils::ensureLeft($cacheKey, '.');
    }

    public static function delayableQueueCacheKey(?string $key = null): string
    {
        if (is_string($key)) {
            if ($key !== '') {
                self::$_delayableQueueCacheKey = $key;
            }

            return '';
        }

        $prefix = rtrim(Cache::cacheKeyPrefix());
        $cacheKey = self::$_delayableQueueCacheKey;

        if ($prefix === '') {
            return $cacheKey;
        }

        return $prefix . StringUtils::ensureLeft($cacheKey, '.');
    }

    public static function publish(
        string $taskClass,
        ?array $taskParams = null,
        ?RetryPolicy $retryPolicy = null
    ): void
    {
        $taskClass = str_replace("\\", '/', $taskClass);
        $taskClass = StringUtils::ensureLeft($taskClass, '/');

        if (empty($taskParams)) {
            $taskParams = [];
        }

        $payload = compact('taskClass', 'taskParams');

        if ($retryPolicy !== null) {
            $payload = array_merge($payload, $retryPolicy->toArray());
        }

        $cacheKey = self::normalQueueCacheKey();
        $logger = Log::channel('task');
        $enableLogging = AppConf::getBoolean('logging.enable-task-log');

        try {
            RedisCmd::rPush($cacheKey, JsonUtils::toJson($payload));

            $msg = sprintf(
                'success to publish normal task: %s, params: %s',
                ltrim(str_replace('/', "\\", $taskClass), "\\"),
                empty($taskParams) ? '{}' : JsonUtils::toJson($taskParams)
            );

            if ($enableLogging) {
                $logger->info($msg);
            }
        } catch (Throwable $ex) {
            $logger->error(ExceptionUtils::getStackTrace($ex));
        }
    }

    public static function publishDelayable(
        string $taskClass,
        array $taskParams,
        int $runAt,
        ?RetryPolicy $retryPolicy = null
    ): void
    {
        $taskClass = str_replace("\\", '/', $taskClass);
        $taskClass = StringUtils::ensureLeft($taskClass, '/');

        if (empty($taskParams)) {
            $taskParams = [];
        }

        $payload = compact('taskClass', 'taskParams');
        $payload['runAt'] = Carbon::createFromTimestamp($runAt, new DateTimeZone('Asia/Shanghai'))->format(DateTimeFormat::FULL);

        if ($retryPolicy !== null) {
            $payload = array_merge($payload, $retryPolicy->toArray());
        }

        $cacheKey = self::delayableQueueCacheKey();
        $logger = Log::channel('task');
        $enableLogging = AppConf::getBoolean('logging.enable-task-log');

        try {
            RedisCmd::zAdd($cacheKey, $runAt, JsonUtils::toJson($payload));

            $msg = sprintf(
                'success to publish delayable task: %s, run at: %s, params: %s',
                ltrim(str_replace('/', "\\", $taskClass), "\\"),
                $payload['runAt'],
                empty($taskParams) ? '{}' : JsonUtils::toJson($taskParams)
            );

            if ($enableLogging) {
                $logger->info($msg);
            }
        } catch (Throwable $ex) {
            $logger->error(ExceptionUtils::getStackTrace($ex));
        }
    }

    public static function publishDelayableWithDelayAmount(
        string $taskClass,
        array $taskParams,
        int $delayAmount,
        ?int $timeUnit = null,
        ?RetryPolicy $retryPolicy = null
    ): void
    {
        if ($delayAmount < 1) {
            return;
        }

        $units = [TimeUnit::SECONDS, TimeUnit::MINUTES, TimeUnit::HOURS, TimeUnit::DAYS];

        if ($timeUnit === null || !in_array($timeUnit, $units)) {
            return;
        }

        $now = Carbon::now(new DateTimeZone('Asia/Shanghai'));

        switch ($timeUnit) {
            case TimeUnit::MINUTES:
                $runAt = $now->addMinutes($delayAmount);
                break;
            case TimeUnit::HOURS:
                $runAt = $now->addHours($delayAmount);
                break;
            case TimeUnit::DAYS:
                $runAt = $now->addDays($delayAmount);
                break;
            default:
                $runAt = $now->addSeconds($delayAmount);
                break;
        }

        self::publishDelayable($taskClass, $taskParams, $runAt->timestamp, $retryPolicy);
    }
}
