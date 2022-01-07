<?php

namespace phpboot\logging;

use phpboot\common\util\ExceptionUtils;
use Psr\Log\LoggerInterface;
use Throwable;

trait LogAbleTrait
{
    private ?LoggerInterface $logger = null;
    private bool $enableLogging = true;

    public function writeDebugLog(string $log, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('debug', $log, $context, $force);
    }

    public function writeInfoLog(string $log, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('info', $log, $context, $force);
    }

    public function writeErrorLog(string|Throwable $arg0, ?array $context = null, bool $force = false): void
    {
        $this->writeLog('error', $arg0, $context, $force);
    }

    public function writeLog(string $logLevel, string|Throwable $msg, ?array $context = null, bool $force = false): void
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
