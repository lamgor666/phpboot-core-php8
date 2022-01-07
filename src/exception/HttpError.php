<?php

namespace phpboot\exception;

final class HttpError
{
    /**
     * @var int
     */
    private $statusCode;

    private function __construct(int $statusCode)
    {
        if ($statusCode < 400) {
            $statusCode = 500;
        }

        $this->statusCode = $statusCode;
    }

    public static function create(int $statusCode): HttpError
    {
        return new self($statusCode);
    }

    /**
     * @return int
     */
    public function getStatusCode(): int
    {
        return $this->statusCode;
    }
}
