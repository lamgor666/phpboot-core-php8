<?php

namespace phpboot\caching;

class NoopCache implements CacheInterface
{
    public function get(string $key, $default = null)
    {
        return null;
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        return false;
    }

    public function delete(string $key): bool
    {
        return false;
    }

    public function clear(): bool
    {
        return false;
    }

    public function getMultiple(iterable $keys, $default = null): array
    {
        return [];
    }

    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        return false;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        return false;
    }

    public function has(string $key): bool
    {
        return false;
    }
}
