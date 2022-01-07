<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * @Annotation
 * @Target("CLASS")
 */
class CliCommand
{
    /**
     * @var bool
     */
    private $disabled;

    public function __construct($arg0 = null)
    {
        $disabled = false;

        if (is_bool($arg0)) {
            $disabled = $arg0 === true;
        } else if (is_array($arg0) && is_bool($arg0['disabled'])) {
            $disabled = $arg0['disabled'] === true;
        }

        $this->disabled = $disabled;
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }
}
