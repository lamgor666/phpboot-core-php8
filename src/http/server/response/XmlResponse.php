<?php

namespace phpboot\http\server\response;

use phpboot\common\constant\Regexp;
use phpboot\exception\HttpError;
use phpboot\common\util\ArrayUtils;

class XmlResponse implements ResponsePayload
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

    /**
     * @param array $map1
     * @param array|string|null $cdataKeys
     * @return XmlResponse
     */
    public static function fromMap(array $map1, $cdataKeys = null): self
    {
        $_cdataKeys = [];

        if (is_string($cdataKeys) && $cdataKeys !== '') {
            $_cdataKeys = preg_split(Regexp::COMMA_SEP, trim($cdataKeys));
        } else if (ArrayUtils::isStringArray($cdataKeys)) {
            $_cdataKeys = $cdataKeys;
        }

        return new self(ArrayUtils::toxml($map1, $_cdataKeys));
    }

    public function getContentType(): string
    {
        return 'text/xml; charset=utf-8';
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
