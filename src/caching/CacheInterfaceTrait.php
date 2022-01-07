<?php

namespace phpboot\caching;

trait CacheInterfaceTrait
{
    /**
     * @param iterable $keys
     * @param mixed $default
     * @return array
     */
    public function getMultiple(iterable $keys, $default = null): array
    {
        $ret = [];

        if (!method_exists($this, 'get')) {
            return $ret;
        }

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $ret[$key] = $this->get($key, $default);
        }

        return $ret;
    }

    public function setMultiple(iterable $values, ?int $ttl = null): bool
    {
        if (!method_exists($this, 'set')) {
            return false;
        }

        foreach ($values as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $success = $this->set($key, $value, $ttl);

            if (!$success) {
                return false;
            }
        }

        return true;
    }

    public function deleteMultiple(iterable $keys): bool
    {
        if (!method_exists($this, 'delete')) {
            return false;
        }

        foreach ($keys as $key) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $success = $this->delete($key);

            if (!$success) {
                return false;
            }
        }

        return true;
    }

    private function buildCacheKey(string $cacheKey): string
    {
        return Cache::buildCacheKey($cacheKey);
    }
}
