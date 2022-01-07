<?php

namespace phpboot\annotation;

use Attribute;
use phpboot\common\Cast;

#[Attribute(Attribute::TARGET_METHOD)]
final class JwtClaim
{
    /**
     * @var string
     */
    private string $name;

    /**
     * @var string
     */
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
