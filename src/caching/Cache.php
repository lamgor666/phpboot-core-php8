<?php

namespace phpboot\caching;

use phpboot\common\AppConf;
use phpboot\common\util\FileUtils;
use phpboot\common\util\StringUtils;

final class Cache
{
    private static array $map1 = [];

    private function __construct()
    {
    }

    public static function cacheKeyPrefix(?string $prefix = null): string
    {
        $key = 'cacheKey_prefix';

        if (is_string($prefix) && $prefix !== '') {
            self::$map1[$key] = $prefix;
            return '';
        }

        $s1 = self::$map1[$key];

        if (!is_string($s1) || $s1 === '') {
            return AppConf::getEnv();
        }

        return StringUtils::ensureRight($s1, '.') . AppConf::getEnv();
    }

    public static function buildCacheKey(string $cacheKey): string
    {
        $prefix = rtrim(self::cacheKeyPrefix(), '.');

        if (empty($prefix)) {
            $prefix = AppConf::getEnv();
        }

        return $prefix . StringUtils::ensureLeft($cacheKey, '.');
    }

    public static function withFileCache(string $cacheDir, string $storeName = 'file'): void
    {
        $cacheDir = FileUtils::getRealpath($cacheDir);
        $cacheDir = str_replace("\\", '/', $cacheDir);

        if ($cacheDir !== '/') {
            $cacheDir = rtrim($cacheDir);
        }

        if (!is_dir($cacheDir) || !is_writable($cacheDir)) {
            return;
        }

        $key = "stores_$storeName";
        self::$map1[$key] = new FileCache($cacheDir);
    }

    public static function withRedisCache(string $storeName = 'redis'): void
    {
        $key = "stores_$storeName";
        self::$map1[$key] = new RedisCache();
    }

    public static function withSwooleCache(string $storeName = 'swoole'): void
    {
        $key = "stores_$storeName";
        self::$map1[$key] = new SwooleCache();
    }

    public static function defaultStore(?string $name = null): CacheInterface
    {
        $key = 'default_store';

        if (is_string($name)) {
            if ($name !== '') {
                self::$map1[$key] = $name;
            }

            return new NoopCache();
        }

        $storeName = self::$map1[$key];

        if (!is_string($storeName) || $storeName === '') {
            return new NoopCache();
        }

        $key = "stores_$storeName";
        $cache = self::$map1[$key];
        return $cache instanceof CacheInterface ? $cache : new NoopCache();
    }

    public static function store(string $name = ''): CacheInterface
    {
        if (empty($name)) {
            return self::defaultStore();
        }

        $key = "stores_$name";
        $cache = self::$map1[$key];
        return $cache instanceof CacheInterface ? $cache : new NoopCache();
    }

    public static function get(string $key, $default = null)
    {
        return self::defaultStore()->get($key, $default);
    }

    public static function set(string $key, $value, ?int $ttl = null): bool
    {
        return self::defaultStore()->set($key, $value, $ttl);
    }

    public static function delete(string $key): bool
    {
        return self::defaultStore()->delete($key);
    }

    public static function has(string $key): bool
    {
        return self::defaultStore()->has($key);
    }
}
