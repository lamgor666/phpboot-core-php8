<?php

namespace phpboot\exception;

use RuntimeException;

class JwtAuthException extends RuntimeException
{
    private int $errno;

    public function __construct(int $errno, string $errorTips = '')
    {
        parent::__construct($errorTips);
        $this->errno = $errno;
    }

    /**
     * @return int
     */
    public function getErrno(): int
    {
        return $this->errno;
    }
}
