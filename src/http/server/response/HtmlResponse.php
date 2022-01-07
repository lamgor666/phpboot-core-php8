<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;

final class HtmlResponse implements ResponsePayload
{
    /**
     * @var string
     */
    private $contents;

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

    /**
     * @return string|HttpError
     * @noinspection PhpReturnDocTypeMismatchInspection
     */
    public function getContents()
    {
        return $this->contents;
    }
}
