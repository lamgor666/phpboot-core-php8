<?php

namespace phpboot\caching;

interface CacheInterface
{
    public function get(string $key, $default = null);

    public function set(string $key, $value, ?int $ttl = null): bool;

    public function delete(string $key): bool;

    public function clear(): bool;

    public function getMultiple(iterable $keys, $default = null): array;

    public function setMultiple(iterable $values, ?int $ttl = null): bool;

    public function deleteMultiple(iterable $keys): bool;

    public function has(string $key): bool;
}
