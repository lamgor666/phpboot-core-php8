<?php

namespace phpboot\caching;

use phpboot\common\Cast;
use phpboot\common\swoole\SwooleTable;
use phpboot\common\util\SerializeUtils;

class SwooleCache implements CacheInterface
{
    use CacheInterfaceTrait;

    public function get(string $key, $default = null)
    {
        $tableName = SwooleTable::cacheTableName();
        $cacheKey = $this->buildCacheKey($key);
        $entry = SwooleTable::getValue($tableName, $cacheKey);

        if (!is_array($entry) || !isset($entry['value'])) {
            return $default;
        }

        $expiry = Cast::toInt($entry['expiry']);

        if ($entry > 0 && time() > $expiry) {
            SwooleTable::remove($tableName, $cacheKey);
            return $default;
        }

        return SerializeUtils::unserialize($entry['value']);
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $tableName = SwooleTable::cacheTableName();
        $cacheKey = $this->buildCacheKey($key);
        $value = SerializeUtils::serialize($value);
        $expiry = is_int($ttl) && $ttl > 0 ? time() + $ttl : 0;
        SwooleTable::setValue($tableName, $cacheKey, compact('value', 'expiry'));
        return true;
    }

    public function delete(string $key): bool
    {
        $tableName = SwooleTable::cacheTableName();
        $cacheKey = $this->buildCacheKey($key);
        SwooleTable::remove($tableName, $cacheKey);
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        $tableName = SwooleTable::cacheTableName();
        $cacheKey = $this->buildCacheKey($key);
        return SwooleTable::exists($tableName, $cacheKey);
    }
}
