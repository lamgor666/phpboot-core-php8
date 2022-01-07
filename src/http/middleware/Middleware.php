<?php

namespace phpboot\http\middleware;

use phpboot\http\server\Request;
use phpboot\http\server\Response;

interface Middleware
{
    const PRE_HANDLE_MIDDLEWARE = 1;
    const POST_HANDLE_MIDDLEWARE = 2;
    const HIGHEST_ORDER = 1;
    const LOWEST_ORDER = 255;

    public function getType(): int;

    public function getOrder(): int;

    public function handle(Request $req, Response $resp): void;
}
