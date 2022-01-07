<?php

namespace phpboot\logging;

use phpboot\common\swoole\Swoole;
use phpboot\common\util\ArrayUtils;
use Psr\Log\LoggerInterface;

final class LogContext
{
    /**
     * @var array
     */
    private static $map1 = [];

    private function __construct()
    {
    }

    /**
     * @param Logger|array $arg0
     * @param int|null $workerId
     */
    public static function withLogger($arg0, ?int $workerId = null): void
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

        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "loggers_worker$workerId";
        } else {
            $key = 'loggers_noworker';
        }

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

    public static function getLogger(string $name, ?int $workerId = null): LoggerInterface
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "loggers_worker$workerId";
        } else {
            $key = 'loggers_noworker';
        }

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

    public static function withRuntimeLogger(string $name = 'runtime', ?int $workerId = null): void
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "runtimeLogger_worker$workerId";
        } else {
            $key = 'runtimeLogger_noworker';
        }

        self::$map1[$key] = self::getLogger($name, $workerId);
    }

    public static function getRuntimeLogger(?int $workerId = null): LoggerInterface
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "runtimeLogger_worker$workerId";
        } else {
            $key = 'runtimeLogger_noworker';
        }

        $logger = self::$map1[$key];
        return $logger instanceof Logger ? $logger : Logger::create(['noop' => true]);
    }

    public static function withRequestLogLogger(string $name = 'request', ?int $workerId = null): void
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "requestLogLogger_worker$workerId";
        } else {
            $key = 'requestLogLogger_noworker';
        }

        self::$map1[$key] = self::getLogger($name);
    }

    public static function getRequestLogLogger(?int $workerId = null): ?LoggerInterface
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "requestLogLogger_worker$workerId";
        } else {
            $key = 'requestLogLogger_noworker';
        }

        $logger = self::$map1[$key];
        return $logger instanceof Logger && !$logger->isNoop() ? $logger : null;
    }

    public static function requestLogEnabled(?int $workerId = null): bool
    {
        return self::getRequestLogLogger($workerId) !== null;
    }

    public static function withExecuteTimeLogLogger(string $name = 'request', ?int $workerId = null): void
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "executeTimeLogLogger_worker$workerId";
        } else {
            $key = 'executeTimeLogLogger_noworker';
        }

        self::$map1[$key] = self::getLogger($name);
    }

    public static function getExecuteTimeLogLogger(?int $workerId = null): ?LoggerInterface
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "executeTimeLogLogger_worker$workerId";
        } else {
            $key = 'executeTimeLogLogger_noworker';
        }

        $logger = self::$map1[$key];
        return $logger instanceof Logger && !$logger->isNoop() ? $logger : null;
    }

    public static function executeTimeLogEnabled(?int $workerId = null): bool
    {
        return self::getExecuteTimeLogLogger($workerId) !== null;
    }
}
