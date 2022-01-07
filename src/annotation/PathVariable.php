<?php

namespace phpboot\annotation;

use Attribute;

#[Attribute(Attribute::TARGET_METHOD)]
final class PathVariable
{
    private string $name;
    private string $defaultValue;

    public function __construct(string $name = '', string $defaultValue = '')
    {
        $this->name = $name;
        $this->defaultValue = $defaultValue;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }
}
