<?php

namespace phpboot\exception;

use RuntimeException;

final class ValidateException extends RuntimeException
{
    /**
     * @var bool
     */
    private $failfast = false;

    /**
     * @var array|null
     */
    private $validateErrors = null;

    public function __construct(...$args)
    {
        $validateErrors = null;
        $errorTips = null;
        $isFailfast = null;

        foreach ($args as $arg) {
            if (is_array($arg)) {
                if (!is_array($validateErrors)) {
                    $validateErrors = $arg;
                }

                continue;
            }

            if (is_bool($arg)) {
                if (!is_bool($isFailfast)) {
                    $isFailfast = $arg;
                }

                continue;
            }

            if (is_string($arg)) {
                if (!is_string($errorTips)) {
                    $errorTips = $arg;
                }
            }
        }

        parent::__construct(empty($errorTips) ? '' : $errorTips);

        if (is_bool($isFailfast)) {
            $this->failfast = $isFailfast;
        }

        if (is_array($validateErrors)) {
            $this->validateErrors = $validateErrors;
        }
    }

    /**
     * @return bool
     */
    public function isFailfast(): bool
    {
        return $this->failfast;
    }

    /**
     * @return array
     */
    public function getValidateErrors(): array
    {
        return is_array($this->validateErrors) ? $this->validateErrors : [];
    }
}
