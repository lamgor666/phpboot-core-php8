<?php

namespace phpboot\annotation;

use phpboot\common\Cast;

/**
 * @Annotation
 */
final class PathVariable
{
    /**
     * @var string
     */
    private $name;

    /**
     * @var string
     */
    private $defaultValue;

    public function __construct($arg0 = null)
    {
        $name = '';
        $defaultValue = '';

        if (is_string($arg0)) {
            $name = $arg0;
        } else if (is_array($arg0)) {
            if (is_string($arg0['value'])) {
                $name = $arg0['value'];
            } else if (is_string($arg0['name'])) {
                $name = $arg0['name'];
            }

            if (isset($arg0['defaultValue'])) {
                $defaultValue = Cast::toString($arg0['defaultValue']);
            }
        }

        $this->name = empty($name) ? '' : $name;
        $this->defaultValue = $defaultValue;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }

    /**
     * @return string
     */
    public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }
}
