<?php

namespace phpboot\annotation;

use phpboot\common\Cast;
use phpboot\common\constant\ReqParamSecurityMode as SecurityMode;

/**
 * @Annotation
 */
final class RequestParam
{

    /**
     * @var string
     */
    private $name;

    /**
     * @var bool
     */
    private $decimal;

    /**
     * @var int
     */
    private $securityMode;

    /**
     * @var string
     */
    private $defaultValue;

    public function __construct($arg0 = null)
    {
        $name = '';
        $decimal = false;
        $securityMode = SecurityMode::STRIP_TAGS;
        $defaultValue = '';

        $modes = [
            SecurityMode::NONE,
            SecurityMode::HTML_PURIFY,
            SecurityMode::STRIP_TAGS
        ];

        if (is_string($arg0) && $arg0 !== '') {
            $name = $arg0;
        } else if (is_array($arg0) && !empty($arg0)) {
            if (is_string($arg0['value']) && $arg0['value'] !== '') {
                $name = $arg0['value'];
            } else if (is_string($arg0['name']) && $arg0['name'] !== '') {
                $name = $arg0['name'];
            }

            if (is_bool($arg0['decimal'])) {
                $decimal = $arg0['decimal'];
            }

            if (is_int($arg0['securityMode']) && in_array($arg0['securityMode'], $modes)) {
                $securityMode = $arg0['securityMode'];
            } else if (is_string($arg0['securityMode']) && is_numeric($arg0['securityMode'])) {
                $n1 = Cast::toInt($arg0['securityMode']);

                if (in_array($n1, $modes)) {
                    $securityMode = $n1;
                }
            }

            if (isset($arg0['defaultValue'])) {
                $defaultValue = Cast::toString($arg0['defaultValue']);
            }
        }

        $this->name = $name;
        $this->decimal = $decimal;
        $this->securityMode = $securityMode;
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
     * @return bool
     */
    public function isDecimal(): bool
    {
        return $this->decimal;
    }

    /**
     * @return int
     */
    public function getSecurityMode(): int
    {
        return $this->securityMode;
    }

    /**
     * @return string
     */
    public function getDefaultValue(): string
    {
        return $this->defaultValue;
    }
}
