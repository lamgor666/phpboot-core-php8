<?php

namespace phpboot\logging;

use Carbon\Carbon;
use DateTimeZone;
use phpboot\common\constant\DateTimeFormat;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\FileUtils;
use phpboot\common\util\StringUtils;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;
use Throwable;

final class Logger implements LoggerInterface
{
    const SUPPORTED_LEVELS = [
        LogLevel::DEBUG,
        LogLevel::INFO,
        LogLevel::NOTICE,
        LogLevel::WARNING,
        LogLevel::ERROR,
        LogLevel::CRITICAL,
        LogLevel::ALERT,
        LogLevel::EMERGENCY
    ];

    /**
     * @var string
     */
    private $channel = '';

    /**
     * @var int
     */
    private $minLevel = 0;

    /**
     * @var string
     */
    private $appenderType = 'file';

    /**
     * @var string
     */
    private $filepath = '';

    /**
     * @var int|string
     */
    private $maxSize = '';

    /**
     * @var mixed
     */
    private $alyslsAppender = null;

    /**
     * @var bool
     */
    private $noop = false;

    private function __construct(?array $settings = null)
    {
        if (empty($settings)) {
            return;
        }

        if ($settings['noop'] === true) {
            $this->noop = true;
            return;
        }

        if (is_string($settings['channel']) && $settings['channel'] !== '') {
            $this->channel = $settings['channel'];
        } else if (is_string($settings['name']) && $settings['name'] !== '') {
            $this->channel = $settings['name'];
        }

        if (is_string($settings['level']) && $settings['level'] !== '') {
            $this->minLevel = $this->parseLevel($settings['level']);
        }

        if (is_string($settings['appenderType']) && $settings['appenderType'] !== '') {
            $this->appenderType = strtolower($settings['appenderType']);
        } else if (is_string($settings['appender-type']) && $settings['appender-type'] !== '') {
            $this->appenderType = strtolower($settings['appender-type']);
        }

        if (is_int($settings['maxSize']) && $settings['maxSize'] > 0) {
            $this->maxSize = $settings['maxSize'];
        } else if (is_string($settings['maxSize']) && $settings['maxSize'] !== '') {
            $this->maxSize = $settings['maxSize'];
        } else if (is_int($settings['max-size']) && $settings['max-size'] > 0) {
            $this->maxSize = $settings['max-size'];
        } else if (is_string($settings['max-size']) && $settings['max-size'] !== '') {
            $this->maxSize = $settings['max-size'];
        }

        $this->handleFilepath($settings);
    }

    public static function create(?array $settings = null): self
    {
        return new self($settings);
    }

    public function withAlyslsAppender(callable $callback): void
    {
        $this->alyslsAppender = $callback;
    }

    /**
     * @return string
     */
    public function getChannel(): string
    {
        return $this->channel;
    }

    /**
     * @return bool
     */
    public function isNoop(): bool
    {
        return $this->noop;
    }

    public function emergency($message, array $context = [])
    {
        $this->log('emergency', $message, $context);
    }

    public function alert($message, array $context = [])
    {
        $this->log('alert', $message, $context);
    }

    public function critical($message, array $context = [])
    {
        $this->log('critical', $message, $context);
    }

    public function error($message, array $context = [])
    {
        $this->log('error', $message, $context);
    }

    public function warning($message, array $context = [])
    {
        $this->log('warning', $message, $context);
    }

    public function notice($message, array $context = [])
    {
        $this->log('notice', $message, $context);
    }

    public function info($message, array $context = [])
    {
        $this->log('info', $message, $context);
    }

    public function debug($message, array $context = [])
    {
        $this->log('debug', $message, $context);
    }

    public function log($level, $message, array $context = [])
    {
        if ($this->noop) {
            return;
        }

        if (!is_string($level) || $level === '' || !is_string($message) || $message === '') {
            return;
        }

        $level = strtolower($level);

        if (!in_array($level, self::SUPPORTED_LEVELS)) {
            return;
        }

        if (!$this->isGteMinLevel($level)) {
            return;
        }

        $message = trim($message);

        if (is_array($context) && !empty($context)) {
            $message = strtr($message, $context);
        }

        if ($message == '') {
            return;
        }

        $logTime = Carbon::now(new DateTimeZone('Asia/Shanghai'))->format(DateTimeFormat::FULL);

        if (Swoole::inCoroutineMode(true)) {
            $this->writeLogAsync($logTime, $level, $message);
            return;
        }

        if ($this->appenderType === '' || in_array($this->appenderType, ['file', 'both'])) {
            $this->writeToFile($logTime, $level, $message);
        }

        if (in_array($this->appenderType, ['alysls', 'both'])) {
            $this->writeToAlysls($logTime, $level, $message);
        }
    }

    public function writeToFile(string $logTime, string $level, string $msg): void
    {
        $filepath = $this->filepath;

        if ($filepath === '') {
            return;
        }

        $this->rollingFile();

        if (!is_file($filepath)) {
            fclose(fopen($filepath, 'w'));
        }

        $fp = fopen($filepath, 'a');

        if (!is_resource($fp)) {
            return;
        }

        $sb = [
            "[$logTime]",
        ];

        if ($this->channel !== '') {
            $sb[] = "[$this->channel]";
        }

        $sb[] = "[$level] $msg";
        flock($fp, LOCK_EX);
        fwrite($fp, StringUtils::ensureRight(implode('', $sb), "\n"));
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    public function writeToAlysls(string $logTime, string $level, string $msg): void
    {
        $callback = $this->alyslsAppender;

        if (!is_callable($callback)) {
            return;
        }

        $channel = $this->channel;

        $logTime = Carbon::createFromTimestamp(strtotime($logTime), new DateTimeZone('Asia/Shanghai'))
            ->format(DateTimeFormat::FULL);

        try {
            $callback(compact('logTime', 'channel', 'level', 'msg'));
        } catch (Throwable $ex) {
        }
    }

    private function parseLevel(string $level): int
    {
        if ($level === '') {
            return 0;
        }

        $level = strtolower($level);
        $idx = array_search($level, self::SUPPORTED_LEVELS);
        return is_int($idx) && $idx >= 0 ? $idx : 0;
    }

    private function isGteMinLevel(string $level): bool
    {
        return $this->parseLevel($level) >= $this->minLevel;
    }

    private function handleFilepath(array $settings): void
    {
        if (strtolower($this->appenderType) === 'alysls') {
            return;
        }

        if (is_string($settings['filepath']) && $settings['filepath'] !== '') {
            $filepath = str_replace("\\", '/', $settings['filepath']);

            if (!str_contains($filepath, '/')) {
                $filepath = FileUtils::getRealpath("classpath:logs/$filepath");
            }

            $this->filepath = $filepath;
            return;
        }

        if ($this->channel === '') {
            return;
        }

        $this->filepath = FileUtils::getRealpath("classpath:logs/$this->channel.log");
    }

    private function rollingFile(): void
    {
        if ($this->filepath === '' || !is_file($this->filepath)) {
            return;
        }

        $maxSize = 0;

        if (is_int($this->maxSize)) {
            $maxSize = $this->maxSize;
        } else if (is_string($this->maxSize) && $this->maxSize !== '') {
            $maxSize = StringUtils::toDataSize($this->maxSize);
        }

        if ($maxSize < 1) {
            $maxSize = 10 * 1024 * 1024;
        }

        $fileSize = (int) filesize($this->filepath);

        if ($fileSize <= $maxSize) {
            return;
        }

        $dir = dirname($this->filepath);
        $fname = StringUtils::ensureRight(basename($this->filepath), '.log');
        $files = [];

        for ($i = 9; $i <= 1; $i--) {
            $search = "$dir/" . preg_replace('/\.log$/', ".$i.log", $fname);

            if (!is_file($search)) {
                continue;
            }

            if (count($files) < 2) {
                $files[] = $search;
            }

            unlink($search);
        }

        if (count($files) > 1) {
            list($file1, $file2) = $files;
            $files = [$file2, $file1];
        }

        foreach ($files as $i => $fpath) {
            $n1 = $i + 1;
            $newpath = "$dir/" . preg_replace('/\.log$/', ".$n1.log", $fname);

            if ($fpath === $newpath) {
                continue;
            }

            rename($fpath, $newpath);
        }

        $num = count($files) + 1;
        $bakFilepath = "$dir/" . preg_replace('/\.log$/', ".$num.log", $fname);
        file_put_contents($bakFilepath, file_get_contents($this->filepath));
        fclose(fopen($this->filepath, 'w'));
    }

    private function writeLogAsync(string $logTime, string $level, string $msg): void
    {
        if ($this->appenderType === '' || $this->appenderType === 'file') {
            $this->writeToFile($logTime, $level, $msg);
            return;
        }

        if ($this->appenderType === 'alysls') {
            $this->writeToAlysls($logTime, $level, $msg);
            return;
        }

        /** @noinspection PhpFullyQualifiedNameUsageInspection */
        $wg = new \Swoole\Coroutine\WaitGroup();
        $wg->add(2);

        go(function () use ($wg, $logTime, $level, $msg) {
            $this->writeToFile($logTime, $level, $msg);
            $wg->done();
        });

        go(function () use ($wg, $logTime, $level, $msg) {
            $this->writeToAlysls($logTime, $level, $msg);
            $wg->done();
        });

        $wg->wait(2.0);
    }
}
