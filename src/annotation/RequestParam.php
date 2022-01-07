<?php

namespace phpboot\annotation;

use Attribute;
use phpboot\common\constant\ReqParamSecurityMode as SecurityMode;

#[Attribute(Attribute::TARGET_METHOD)]
final class RequestParam
{
    private string $name;
    private bool $decimal;
    private int $securityMode;
    private string $defaultValue;

    public function __construct(string $name = '', bool $decimal = false, int $securityMode = SecurityMode::STRIP_TAGS, string $defaultValue = '')
    {
        $this->name = $name;
        $this->decimal = $decimal;
        $this->securityMode = $securityMode;
        $this->defaultValue = $defaultValue;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function isDecimal(): bool
    {
        return $this->decimal;
    }

    public function getSecurityMode(): int
    {
        return $this->securityMode;
    }

    public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }
}
