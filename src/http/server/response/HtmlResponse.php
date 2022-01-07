<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;

final class HtmlResponse implements ResponsePayload
{
    private string $contents;

    private function __construct(string $contents = '')
    {
        $this->contents = $contents;
    }

    public static function withContents(string $contents): self
    {
        return new self($contents);
    }

    public function getContentType(): string
    {
        return 'text/html; charset=utf-8';
    }

    public function getContents(): string|HttpError
    {
        return $this->contents;
    }
}
