<?php

namespace phpboot\http\middleware;

use phpboot\exception\ValidateException;
use phpboot\http\server\Request;
use phpboot\http\server\Response;
use phpboot\validator\DataValidator;
use phpboot\common\util\JsonUtils;
use phpboot\common\util\StringUtils;

class MidValidate implements Middleware
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
        $rules = $req->getContextParam('validateRules');

        if (is_string($rules)) {
            $rules = JsonUtils::arrayFrom(str_replace('[syh]', '"', $rules));
        }

        if (!is_array($rules) || empty($rules)) {
            return;
        }

        $failfast = false;
        $validateRules = [];

        foreach ($rules as $s1) {
            if (!is_string($s1) || $s1 == '') {
                continue;
            }

            if (in_array($s1, ['true', 'false'])) {
                $failfast = $s1 === 'true';
                continue;
            }

            $validateRules[] = $s1;
        }

        if (empty($validateRules)) {
            return;
        }

        $isGet = $req->getMethod() === 'GET';
        $contentType = $req->getHeader('Content-Type');
        $isJsonPayload = stripos($contentType, 'application/json') !== false;

        $isXmlPayload = stripos($contentType, 'application/xml') !== false
            || stripos($contentType, 'text/xml') !== false;

        if ($isGet) {
            $data = $req->getQueryParams();
        } else if ($isJsonPayload) {
            $data = JsonUtils::mapFrom($req->getRawBody());
        } else if ($isXmlPayload) {
            $data = StringUtils::xml2assocArray($req->getRawBody());
        } else {
            $data = array_merge($req->getQueryParams(), $req->getFormData());
        }

        if (!is_array($data)) {
            $data = [];
        }

        $result = DataValidator::validate($data, $validateRules, $failfast);

        if ($failfast && is_string($result) && $result !== '') {
            throw new ValidateException(true, $result);
        }

        if (!$failfast && is_array($result) && !empty($result)) {
            throw new ValidateException($result);
        }
    }
}
