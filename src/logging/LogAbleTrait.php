<?php

namespace phpboot\logging;

use phpboot\common\util\ExceptionUtils;
use Psr\Log\LoggerInterface;
use Throwable;

trait LogAbleTrait
{
    /**
     * @var LoggerInterface|null
     */
    private $logger = null;

    /**
     * @var bool
     */
    private $enableLogging = true;

    public function writeDebugLog(string $log, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('debug', $log, $context, $force);
    }

    public function writeInfoLog(string $log, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('info', $log, $context, $force);
    }

    /**
     * @param string|Throwable $arg0
     * @param array|null $context
     * @param bool $force
     */
    public function writeErrorLog($arg0, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('error', $arg0, $context, $force);
    }

    /**
     * @param string $logLevel
     * @param string|Throwable $msg
     * @param array|null $context
     * @param bool $force
     */
    public function writeLog(string $logLevel, $msg, ?array $context = null, bool $force = false): void
    {
        $logger = $this->logger;

        if (!($logger instanceof LoggerInterface)) {
            return;
        }

        if (!is_array($context)) {
            $context = [];
        }

        if (!$this->enableLogging && $logLevel !== 'error' && !$force) {
            return;
        }

        if ($msg instanceof Throwable) {
            $msg = ExceptionUtils::getStackTrace($msg);
        }

        if (!is_string($msg)) {
            return;
        }

        switch ($logLevel) {
            case 'debug':
                $logger->debug($msg, $context);
                break;
            case 'info':
                $logger->info($msg, $context);
                break;
            case 'error':
                $logger->error($msg, $context);
                break;
        }
    }
}
