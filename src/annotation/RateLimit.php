<?php

namespace phpboot\annotation;

use Attribute;
use phpboot\common\Cast;

#[Attribute(Attribute::TARGET_METHOD)]
final class RateLimit
{
    private int $total;
    private int $duration;
    private bool $limitByIp;

    public function __construct(int $total, int|string $duration, bool $limitByIp = false)
    {
        $_duration = 0;

        if (is_int($duration)) {
            $_duration = $duration;
        } else if (is_string($duration)) {
            $_duration = Cast::toDuration($duration);
        }

        $this->total = $total;
        $this->duration = $_duration;
        $this->limitByIp = $limitByIp;
    }

    public function getTotal(): int
    {
        return $this->total;
    }

    public function getDuration(): int
    {
        return $this->duration;
    }

    public function isLimitByIp(): bool
    {
        return $this->limitByIp;
    }
}
