<?php

namespace phpboot\caching;

use phpboot\common\Cast;
use phpboot\dal\redis\RedisCmd;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\SerializeUtils;
use Throwable;

class RedisCache implements CacheInterface
{
    use CacheInterfaceTrait;

    public function get(string $key, $default = null)
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            $contents = RedisCmd::get($cacheKey);

            if (!is_string($contents) || empty($contents)) {
                return $default;
            }

            $entry = SerializeUtils::unserialize($contents);

            if (!ArrayUtils::isAssocArray($entry)) {
                return $default;
            }

            $expiry = Cast::toInt($entry['expiry']);

            if ($expiry > 0 && time() > $expiry) {
                RedisCmd::del($cacheKey);
                return $default;
            }

            return $entry['value'];
        } catch (Throwable $ex) {
            return $default;
        }
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildCacheKey($key);
        $ttl = Cast::toInt($ttl);
        $entry = ['value' => $value];

        if ($ttl > 0) {
            $entry['expiry'] = time() + $ttl;
        }

        $contents = SerializeUtils::serialize($entry);

        if (!is_string($contents) || empty($contents)) {
            return false;
        }

        try {
            if ($ttl > 0) {
                $result = RedisCmd::setex($cacheKey, $ttl, $contents);
            } else {
                $result = RedisCmd::set($cacheKey, $contents);
            }
        } catch (Throwable $ex) {
            $result = false;
        }

        return is_bool($result) ? $result : false;
    }

    public function delete(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            $n1 = RedisCmd::del($cacheKey);
        } catch (Throwable $ex) {
            $n1 = 0;
        }

        return is_int($n1) && $n1 > 0;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);

        try {
            $flag = RedisCmd::exists($cacheKey);
        } catch (Throwable $ex) {
            $flag = false;
        }

        return is_bool($flag) ? $flag : false;
    }
}
