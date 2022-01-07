<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;
use phpboot\common\Cast;

/**
 * @Annotation
 * @Target("METHOD")
 */
final class RateLimit
{
    /**
     * @var int
     */
    private $total;

    /**
     * @var int
     */
    private $duration;

    /**
     * @var bool
     */
    private $limitByIp;

    public function __construct(array $values)
    {
        $total = 0;
        $duration = 0;
        $limitByIp = false;

        if (isset($values['total'])) {
            $n1 = Cast::toInt($values['total']);

            if ($n1 > 0) {
                $total = $n1;
            }
        }

        if (isset($values['duration'])) {
            $n1 = Cast::toDuration($values['duration']);

            if ($n1 > 0) {
                $duration = $n1;
            }
        }

        if (isset($values['limitByIp'])) {
            $limitByIp = Cast::toBoolean($values['limitByIp']);
        }

        $this->total = $total;
        $this->duration = $duration;
        $this->limitByIp = $limitByIp;
    }

    /**
     * @return int
     */
    public function getTotal(): int
    {
        return $this->total;
    }

    /**
     * @return int
     */
    public function getDuration(): int
    {
        return $this->duration;
    }

    /**
     * @return bool
     */
    public function isLimitByIp(): bool
    {
        return $this->limitByIp;
    }
}
