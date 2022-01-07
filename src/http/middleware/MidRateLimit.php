<?php

namespace phpboot\http\middleware;

use phpboot\common\Cast;
use phpboot\common\util\JsonUtils;
use phpboot\dal\ratelimiter\RateLimiter;
use phpboot\exception\RateLimitException;
use phpboot\http\server\Request;
use phpboot\http\server\Response;

class MidRateLimit implements Middleware
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
        $settings = $req->getContextParam('rateLimitSettings');

        if (is_string($settings)) {
            $settings = JsonUtils::mapFrom(str_replace('[syh]', '"', $settings));
        }

        if (!is_array($settings)) {
            return;
        }

        $total = Cast::toInt($settings['total']);
        $duration = Cast::toDuration($settings['duration']);

        if ($total < 1 || $duration < 1) {
            return;
        }

        $id = $req->getContextParam('handlerName');

        if (!is_string($id) || $id === '') {
            return;
        }

        if (Cast::toBoolean($settings['limitByIp'])) {
            $id .= '@' . $req->getClientIp();
        }

        $limiter = RateLimiter::create($id, $total, $duration);
        $info = $limiter->getLimit();

        if (is_int($info['remaining']) && $info['remaining'] < 0) {
            throw new RateLimitException($info);
        }
    }
}
