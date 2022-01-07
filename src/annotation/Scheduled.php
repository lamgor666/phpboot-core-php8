<?php

namespace phpboot\annotation;

use Doctrine\Common\Annotations\Annotation\Target;
use phpboot\common\constant\Regexp;
use phpboot\common\util\StringUtils;

/**
 * @Annotation
 * @Target("CLASS")
 */
class Scheduled
{
    /**
     * @var string
     */
    private $value;

    /**
     * @var bool
     */
    private $disabled;

    public function __construct($arg0)
    {
        $value = '';
        $disabled = false;

        if (is_string($arg0)) {
            $value = $arg0;
        } else if (is_array($arg0)) {
            if (is_string($arg0['value'])) {
                $value = $arg0['value'];
            }

            if (is_bool($arg0['disabled'])) {
                $disabled = $arg0['disabled'];
            }
        }

        $this->value = $value;
        $this->disabled = $disabled;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        $expr = trim($this->value);

        if ($expr === '') {
            return $expr;
        }

        $expr = preg_replace(Regexp::SPACE_SEP, ' ', $expr);

        if (StringUtils::startsWith($expr, 'every')) {
            return '@' . $expr;
        }

        if (StringUtils::startsWith($expr, '@hourly')) {
            return '@every 1h';
        }

        if (StringUtils::startsWith($expr, '@')) {
            return $expr;
        }

        $parts = explode(' ', $expr);
        $n1 = count($parts);

        if ($n1 < 5) {
            for ($i = 1; $i <= 5 - $n1; $i++) {
                $parts[] = '*';
            }
        }

        $regex1 = '~0/([1-9][0-9]+)~';
        $matches = [];
        preg_match($regex1, $parts[0], $matches, PREG_SET_ORDER);

        if (count($matches) > 1) {
            $n2 = (int) $matches[1];

            if ($n2 > 0) {
                return "@every {$n2}m";
            }
        }

        $matches = [];
        preg_match($regex1, $parts[1], $matches, PREG_SET_ORDER);

        if (count($matches) > 1) {
            $n2 = (int) $matches[1];

            if ($n2 > 0) {
                return "@every {$n2}h";
            }
        }

        return implode(' ', $parts);
    }

    /**
     * @return bool
     */
    public function isDisabled(): bool
    {
        return $this->disabled;
    }
}
