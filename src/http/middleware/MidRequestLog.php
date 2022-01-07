<?php

namespace phpboot\http\middleware;

use phpboot\http\server\Request;
use phpboot\http\server\Response;
use phpboot\logging\LogContext;

class MidRequestLog implements Middleware
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
        return Middleware::PRE_HANDLE_MIDDLEWARE;
    }

    public function getOrder(): int
    {
        return Middleware::HIGHEST_ORDER;
    }

    public function handle(Request $req, Response $resp): void
    {
        if (!LogContext::requestLogEnabled()) {
            return;
        }

        $clientIp = $req->getClientIp();
        $httpMethod = $req->getMethod();
        $requestUrl = $req->getRequestUrl(true);

        if ($httpMethod === '' || $requestUrl === '' || $clientIp === '') {
            return;
        }

        $logger = LogContext::getRequestLogLogger();
        $logger->info("$httpMethod $requestUrl from $clientIp");
        $requestBody = $req->getRawBody();

        if ($requestBody !== '') {
            $logger->debug($requestBody);
        }
    }
}
