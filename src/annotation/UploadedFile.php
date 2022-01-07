<?php

namespace phpboot\annotation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class UploadedFile
{
    private string $value;

    public function __construct(string $value = 'file')
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
