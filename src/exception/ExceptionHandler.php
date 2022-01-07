<?php

namespace phpboot\exception;

use phpboot\http\server\response\ResponsePayload;
use Throwable;

interface ExceptionHandler
{
    public function getExceptionClassName(): string;

    public function handleException(Throwable $ex): ?ResponsePayload;
}
