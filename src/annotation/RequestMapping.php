<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;

/**
 * @Annotation
 * @Target({"CLASS", "METHOD"})
 */
final class RequestMapping
{
    /**
     * @var string
     */
    private $value;

    public function __construct($arg0)
    {
        $value = '';

        if (is_string($arg0)) {
            $value = $arg0;
        } else if (is_array($arg0) && is_string($arg0['value'])) {
            $value = $arg0['value'];
        }

        $this->value = $value;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
