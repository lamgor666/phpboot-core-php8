<?php

namespace phpboot\http\middleware;

use phpboot\common\util\StringUtils;
use phpboot\http\server\Request;
use phpboot\http\server\Response;
use phpboot\logging\LogContext;

class MidExecuteTimeLog implements Middleware
{
    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function getType(): int
    {
        return Middleware::POST_HANDLE_MIDDLEWARE;
    }

    public function getOrder(): int
    {
        return Middleware::LOWEST_ORDER;
    }

    public function handle(Request $req, Response $resp): void
    {
        if (!LogContext::executeTimeLogEnabled()) {
            return;
        }

        $httpMethod = $req->getMethod();
        $requestUrl = $req->getRequestUrl(true);

        if ($httpMethod === '' || $requestUrl === '') {
            return;
        }

        $sb = ["$httpMethod $requestUrl"];
        $handlerName = $req->getContextParam('handlerName');

        if (is_string($handlerName) && strpos($handlerName, '@') !== false) {
            $clazz = StringUtils::substringBefore($handlerName, '@');
            $clazz = StringUtils::ensureLeft($clazz, "\\");
            $methodName = StringUtils::substringAfterLast($handlerName, '@');
            $handler = "$clazz@$methodName(...)";
            $sb[] = ", handler: $handler";
        }

        $elapsedTime = $this->calcElapsedTime($req);
        $sb[] = ", total elapsed time: $elapsedTime.";
        $logger = LogContext::getExecuteTimeLogLogger();
        $logger->info(implode('', $sb));
        $resp->addExtraHeader('X-Response-Time', $elapsedTime);
    }

    private function calcElapsedTime(Request $request): string
    {
        $n1 = bcmul(bcsub(microtime(true), $request->getExecStart(), 6), 1000, 6);

        if (bccomp($n1, 1000, 6) !== 1) {
            $n1 = (int) StringUtils::substringBefore($n1, '.');
            $n1 = $n1 < 1 ? 1 : $n1;
            return "{$n1}ms";
        }

        $n1 = bcdiv($n1, 1000, 6);
        $n1 = rtrim($n1, '0');
        $n1 = rtrim($n1, '.');
        return "{$n1}s";
    }
}
