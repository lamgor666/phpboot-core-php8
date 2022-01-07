<?php

namespace phpboot\annotation;

use Attribute;

#[Attribute(Attribute::TARGET_CLASS)]
class CliCommand
{
    private bool $disabled;

    public function __construct(?bool $value = null)
    {
        $disabled = false;

        if (is_bool($value)) {
            $disabled = $value === true;
        }

        $this->disabled = $disabled;
    }

    public function isDisabled(): bool
    {
        return $this->disabled;
    }
}
