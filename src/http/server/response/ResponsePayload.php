<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;

interface ResponsePayload
{
    public function getContentType(): string;

    /**
     * @return string|HttpError
     */
    public function getContents();
}
