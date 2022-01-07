<?php

namespace phpboot\logging;

use Psr\Log\LoggerInterface;

final class Log
{
    private function __construct()
    {
    }

    public static function channel(string $name): LoggerInterface
    {
        return LogContext::getLogger($name);
    }

    public static function debug(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->debug($message, $context);
    }

    public static function info(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->info($message, $context);
    }

    public static function notice(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->notice($message, $context);
    }

    public static function warning(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->warning($message, $context);
    }

    public static function error(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->error($message, $context);
    }

    public static function alert(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->alert($message, $context);
    }

    public static function emergency(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->emergency($message, $context);
    }

    public static function critical(string $message, array $context = []): void
    {
        LogContext::getLogger('runtime')->critical($message, $context);
    }
}
