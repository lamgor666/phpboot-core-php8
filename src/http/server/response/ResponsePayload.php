<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;

interface ResponsePayload
{
    public function getContentType(): string;

    public function getContents(): string|HttpError;
}
