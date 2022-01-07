<?php

namespace phpboot\exception;

use phpboot\common\Cast;
use phpboot\http\server\Response;
use RuntimeException;

class RateLimitException extends RuntimeException
{
    private int $total;
    private int $remaining;
    private string $retryAfter;

    public function __construct(array $data)
    {
        parent::__construct();
        $this->total = Cast::toInt($data['total']);
        $this->remaining = Cast::toInt($data['remaining']);
        $this->retryAfter = Cast::toString($data['remaining']);
    }

    public function addSpecifyHeaders(Response $resp): void
    {
        $total = $this->total;

        if ($total < 1) {
            return;
        }

        $remaining = $this->remaining;

        if ($remaining < 0) {
            $remaining = 0;
        }

        $resp->addExtraHeader('X-Ratelimit-Limit', "$total");
        $resp->addExtraHeader('X-Ratelimit-Remaining', "$remaining");
        $retryAfter = $this->retryAfter;

        if ($retryAfter !== '') {
            $resp->addExtraHeader('Retry-After', $retryAfter);
        }
    }
}
