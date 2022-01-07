<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;
use phpboot\common\constant\Regexp;
use phpboot\common\util\ArrayUtils;

/**
 * @Annotation
 * @Target("METHOD")
 */
final class Validate
{
    /**
     * @var string[]
     */
    private $rules;

    /**
     * @var bool
     */
    private $failfast;

    public function __construct($arg0)
    {
        $rules = [];
        $failfast = false;

        if (is_string($arg0) && $arg0 !== '') {
            $rules = preg_split(Regexp::COMMA_SEP, $arg0);
        } else if (is_array($arg0)) {
            if (is_string($arg0['value']) && $arg0['value'] !== '') {
                $rules = preg_split(Regexp::COMMA_SEP, $arg0['value']);
            } else if (ArrayUtils::isStringArray($arg0['value'])) {
                $rules = $arg0['value'];
            } else if (is_string($arg0['rules']) && $arg0['rules'] !== '') {
                $rules = preg_split(Regexp::COMMA_SEP, $arg0['rules']);
            } else if (ArrayUtils::isStringArray($arg0['rules'])) {
                $rules = $arg0['rules'];
            }

            if (is_bool($arg0['failfast'])) {
                $failfast = $arg0['failfast'];
            }
        }

        $this->rules = $rules;
        $this->failfast = $failfast;
    }

    /**
     * @return string[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }

    /**
     * @return bool
     */
    public function isFailfast(): bool
    {
        return $this->failfast;
    }
}
