<?php

namespace phpboot\annotation;

use Attribute;
use phpboot\common\constant\Regexp;
use phpboot\common\util\ArrayUtils;

#[Attribute(Attribute::TARGET_METHOD)]
final class MapBind
{
    /**
     * @var string[]
     */
    private array $rules;

    public function __construct(array|string $value)
    {
        $rules = [];

        if (is_string($value) && $value !== '') {
            $rules = preg_split(Regexp::COMMA_SEP, $value);
        } else if (ArrayUtils::isStringArray($value)) {
            $rules = $value;
        }

        $this->rules = $rules;
    }

    public function getRules(): array
    {
        return $this->rules;
    }
}
