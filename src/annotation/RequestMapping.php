<?php

namespace phpboot\annotation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD & Attribute::IS_REPEATABLE)]
final class RequestMapping
{
    private string $value;

    public function __construct(string $value)
    {
        $this->value = $value;
    }

    public function getValue(): string
    {
        return $this->value;
    }
}
