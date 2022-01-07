<?php

namespace phpboot\annotation;

/**
 * @Annotation
 */
final class UploadedFile
{
    /**
     * @var string
     */
    private $value;

    public function __construct($arg0 = null)
    {
        $value = '';

        if (is_string($arg0)) {
            $value = $arg0;
        } else if (is_array($arg0) && is_string($arg0['value'])) {
            $value = $arg0['value'];
        }

        $this->value = $value === '' ? 'file' : $value;
    }

    /**
     * @return string
     */
    public function getValue(): string
    {
        return $this->value;
    }
}
