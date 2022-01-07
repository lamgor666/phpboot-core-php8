<?php

namespace phpboot\annotation;

/**
 * @Annotation
 */
final class HttpHeader
{
    /**
     * @var string
     */
    private $name;

    public function __construct($arg0)
    {
        $name = '';

        if (is_string($arg0) && $arg0 !== '') {
            $name = $arg0;
        } else if (is_array($arg0)) {
            if (is_string($arg0['value'])) {
                $name = $arg0['value'];
            } else if (is_string($arg0['name'])) {
                $name = $arg0['name'];
            }
        }

        $this->name = $name;
    }

    /**
     * @return string
     */
    public function getName(): string
    {
        return $this->name;
    }
}
