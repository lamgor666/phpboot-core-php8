<?php

namespace phpboot\exception;

use RuntimeException;

class JwtAuthException extends RuntimeException
{
    /**
     * @var int
     */
    private $errno;

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
