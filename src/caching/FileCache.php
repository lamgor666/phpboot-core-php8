<?php

namespace phpboot\caching;

use phpboot\common\Cast;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\FileUtils;
use phpboot\common\util\SerializeUtils;
use Throwable;

class FileCache implements CacheInterface
{
    use CacheInterfaceTrait;

    /**
     * @var string
     */
    private $cacheDir = '';

    public function __construct(?string $cacheDir = null)
    {
        if (is_string($cacheDir) && $cacheDir !== '') {
            $this->cacheDir = $cacheDir;
        }
    }

    public function get(string $key, $default = null)
    {
        $cacheKey = $this->buildCacheKey($key);
        $cacheDir = $this->getCacheDir($cacheKey);

        if ($cacheDir === '') {
            return $default;
        }

        $cacheFile = $this->getCacheFile($cacheDir, $cacheKey);

        if (!is_file($cacheFile)) {
            return $default;
        }

        if (Swoole::inCoroutineMode(true)) {
            try {
                $contents = $this->readFromFileAsync($cacheFile);
            } catch (Throwable $ex) {
                $contents = '';
            }
        } else {
            $contents = $this->readFromFile($cacheFile);
        }

        $entry = SerializeUtils::unserialize($contents);

        if (!ArrayUtils::isAssocArray($entry)) {
            return $default;
        }

        $expiry = Cast::toInt($entry['expiry']);

        if ($expiry > 0 && time() > $expiry) {
            unlink($cacheFile);
            return $default;
        }

        return $entry['value'];
    }

    public function set(string $key, $value, ?int $ttl = null): bool
    {
        $cacheKey = $this->buildCacheKey($key);
        $cacheDir = $this->getCacheDir($cacheKey);

        if ($cacheDir === '') {
            return false;
        }

        $cacheFile = $this->getCacheFile($cacheDir, $cacheKey);
        $ttl = Cast::toInt($ttl);
        $entry = ['value' => $value];

        if ($ttl > 0) {
            $entry['expiry'] = time() + $ttl;
        }

        $contents = SerializeUtils::serialize($entry);

        if (!is_string($contents) || empty($contents)) {
            return false;
        }

        if (Swoole::inCoroutineMode(true)) {
            try {
                $this->writeToFileAsync($cacheFile, $contents);
                return true;
            } catch (Throwable $ex) {
                return false;
            }
        }

        $this->writeToFile($cacheFile, $contents);
        return true;
    }

    public function delete(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);
        $cacheDir = $this->getCacheDir($cacheKey, false);
        $cacheFile = $this->getCacheFile($cacheDir, $cacheKey);
        is_file($cacheFile) && unlink($cacheFile);
        return true;
    }

    public function clear(): bool
    {
        return true;
    }

    public function has(string $key): bool
    {
        $cacheKey = $this->buildCacheKey($key);
        $cacheDir = $this->getCacheDir($cacheKey, false);

        if ($cacheDir === '') {
            return false;
        }

        $cacheFile = $this->getCacheFile($cacheDir, $cacheKey);
        return is_file($cacheFile);
    }

    private function getCacheDir(string $cacheKey, bool $autoBuild = true): string
    {
        $dir = FileUtils::getRealpath($this->cacheDir);

        if (is_dir($dir)) {
            if (!is_writable($dir)) {
                return '';
            }
        } else if ($autoBuild) {
            if (Swoole::inCoroutineMode(true)) {
                /** @noinspection PhpFullyQualifiedNameUsageInspection */
                $wg = new \Swoole\Coroutine\WaitGroup();
                $wg->add();

                go(function () use ($dir, $wg) {
                    mkdir($dir, 0644, true);
                    $wg->done();
                });

                $wg->wait(1.0);
            } else {
                mkdir($dir, 0644, true);
            }
        }

        if (!is_dir($dir) || !is_writable($dir)) {
            return '';
        }

        $cacheKey = strtolower(md5($cacheKey));
        $dir = sprintf('%s/%s/%s', $dir, substr($cacheKey, 0, 2), substr($cacheKey, -2));

        if (!$autoBuild) {
            return $dir;
        }

        if (Swoole::inCoroutineMode(true)) {
            /** @noinspection PhpFullyQualifiedNameUsageInspection */
            $wg = new \Swoole\Coroutine\WaitGroup();
            $wg->add();

            go(function () use ($dir, $wg) {
                mkdir($dir, 0644, true);
                $wg->done();
            });

            $wg->wait(1.0);
        } else {
            mkdir($dir, 0644, true);
        }

        return is_dir($dir) ? $dir : '';
    }

    private function getCacheFile(string $cacheDir, string $cacheKey): string
    {
        $cacheKey = strtolower(md5($cacheKey));
        return "$cacheDir/$cacheKey.dat";
    }

    private function readFromFile(string $filepath): string
    {
        $contents = file_get_contents($filepath);
        return is_string($contents) ? $contents : '';
    }

    private function readFromFileAsync(string $filepath): string
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add();
        $contents = '';

        go(function () use ($wg, $filepath, &$contents) {
            $contents = file_get_contents($filepath);

            if (!is_string($contents)) {
                $contents = '';
            }

            $wg->done();
        });

        $wg->wait(1.0);
        return $contents;
    }

    private function writeToFile(string $filepath, string $contents): void
    {
        $fp = fopen($filepath, 'w');
        flock($fp, LOCK_EX);
        fwrite($fp, $contents);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private function writeToFileAsync(string $filepath, string $contents): void
    {
        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add();

        go(function () use ($wg, $filepath, $contents) {
            $fp = fopen($filepath, 'w');
            flock($fp, LOCK_EX);
            fwrite($fp, $contents);
            flock($fp, LOCK_UN);
            fclose($fp);
            $wg->done();
        });

        $wg->wait(1.0);
    }
}
