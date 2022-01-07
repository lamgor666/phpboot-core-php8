<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;
use phpboot\common\util\ArrayUtils;

/**
 * @Annotation
 * @Target("METHOD")
 */
final class ParamInject
{
    /**
     * @var array
     */
    private $value;

    public function __construct($arg0)
    {
        $annos = [];

        if (is_array($arg0)) {
            if (ArrayUtils::isList($arg0['value'])) {
                $annos = $arg0['value'];
            } else if (ArrayUtils::isList($arg0)) {
                $annos = $arg0;
            }
        }

        $this->value = $annos;
    }

    /**
     * @return array
     */
    public function getValue(): array
    {
        return $this->value;
    }
}
