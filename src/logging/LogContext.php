<?php

namespace phpboot\logging;

use phpboot\common\util\ArrayUtils;
use Psr\Log\LoggerInterface;

final class LogContext
{
    private static array $map1 = [];

    private function __construct()
    {
    }

    public static function withLogger(Logger|array $arg0): void
    {
        $logger = null;

        if ($arg0 instanceof Logger) {
            $logger = $arg0;
        } else if (is_array($arg0) && !empty($arg0) && ArrayUtils::isAssocArray($arg0)) {
            $logger = Logger::create($arg0);
        }

        if (!($logger instanceof Logger) || $logger->isNoop() || $logger->getChannel() === '') {
            return;
        }

        $key = 'loggers';

        if (!isset(self::$map1[$key])) {
            self::$map1[$key] = [];
        }

        $idx = -1;

        foreach (self::$map1[$key] as $i => $lg) {
            if (!($lg instanceof Logger)) {
                continue;
            }

            if ($lg->getChannel() === $logger->getChannel()) {
                $idx = $i;
                break;
            }
        }

        if ($idx >= 0) {
            self::$map1[$key][$idx] = $logger;
        } else {
            self::$map1[$key][] = $logger;
        }
    }

    public static function getLogger(string $name): LoggerInterface
    {
        $key = 'loggers';

        if (!is_array(self::$map1[$key])) {
            self::$map1[$key] = [];
        }

        foreach (self::$map1[$key] as $logger) {
            if (!($logger instanceof Logger)) {
                continue;
            }

            if ($logger->getChannel() === $name) {
                return $logger;
            }
        }

        return Logger::create(['noop' => true]);
    }

    public static function withRuntimeLogger(string $name = 'runtime'): void
    {
        $key = 'runtime_logger';
        self::$map1[$key] = self::getLogger($name);
    }

    public static function getRuntimeLogger(): LoggerInterface
    {
        $key = 'runtime_logger';
        $logger = self::$map1[$key];
        return $logger instanceof Logger ? $logger : Logger::create(['noop' => true]);
    }

    public static function withRequestLogLogger(string $name = 'request'): void
    {
        $key = 'request_log_logger';
        self::$map1[$key] = self::getLogger($name);
    }

    public static function getRequestLogLogger(): ?LoggerInterface
    {
        $key = 'request_log_logger';
        $logger = self::$map1[$key];
        return $logger instanceof Logger && !$logger->isNoop() ? $logger : null;
    }

    public static function requestLogEnabled(): bool
    {
        return self::getRequestLogLogger() !== null;
    }

    public static function withExecuteTimeLogLogger(string $name = 'request'): void
    {
        $key = 'execute_time_log_logger';
        self::$map1[$key] = self::getLogger($name);
    }

    public static function getExecuteTimeLogLogger(): ?LoggerInterface
    {
        $key = 'execute_time_log_logger';
        $logger = self::$map1[$key];
        return $logger instanceof Logger && !$logger->isNoop() ? $logger : null;
    }

    public static function executeTimeLogEnabled(): bool
    {
        return self::getExecuteTimeLogLogger() !== null;
    }
}
