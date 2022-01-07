<?php

namespace phpboot\http\middleware;

use Lcobucci\JWT\Token;
use phpboot\common\constant\JwtVerifyErrno;
use phpboot\common\util\JwtUtils;
use phpboot\exception\JwtAuthException;
use phpboot\http\server\Request;
use phpboot\http\server\Response;
use phpboot\security\JwtSettings;

class MidJwtAuth implements Middleware
{

    private function __construct()
    {
    }

    public static function create(): self
    {
        return new self();
    }

    public function getType(): int
    {
        return Middleware::PRE_HANDLE_MIDDLEWARE;
    }

    public function getOrder(): int
    {
        return Middleware::HIGHEST_ORDER;
    }

    public function handle(Request $req, Response $resp): void
    {
        $key = $req->getContextParam('jwtAuthKey');

        if (!is_string($key) || $key === '') {
            return;
        }

        $settings = JwtSettings::loadCurrent($key);

        if (!($settings instanceof JwtSettings) || $settings->getIssuer() === '') {
            return;
        }

        $jwt = $req->getJwt();

        if (!($jwt instanceof Token)) {
            throw new JwtAuthException(JwtVerifyErrno::NOT_FOUND);
        }

        list($passed, $errCode) = JwtUtils::verify($jwt, $settings->getIssuer());

        if ($passed) {
            return;
        }

        switch ($errCode) {
            case -1:
                throw new JwtAuthException(JwtVerifyErrno::INVALID);
            case -2:
                throw new JwtAuthException(JwtVerifyErrno::EXPIRED);
        }
    }
}
