<?php

namespace phpboot\annotation;

use phpboot\common\constant\Regexp;
use phpboot\common\util\ArrayUtils;

/**
 * @Annotation
 */
final class MapBind
{
    /**
     * @var string[]
     */
    private $rules;

    public function __construct($arg0 = null)
    {
        $rules = [];

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
            } else if (ArrayUtils::isStringArray($arg0)) {
                $rules = $arg0;
            }
        }

        $this->rules = $rules;
    }

    /**
     * @return string[]
     */
    public function getRules(): array
    {
        return $this->rules;
    }
}
