<?php

namespace phpboot\http\server;

use Lcobucci\JWT\Parser;
use Lcobucci\JWT\Token;
use phpboot\common\Cast;
use phpboot\common\constant\Regexp;
use phpboot\common\constant\ReqParamSecurityMode as SecurityMode;
use phpboot\common\util\JsonUtils;
use phpboot\common\HtmlPurifier;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\JwtUtils;
use phpboot\common\util\StringUtils;
use Throwable;

final class Request
{

    /**
     * @var mixed
     */
    private $swooleHttpRequest = null;

    /**
     * @var string
     */
    private $protocolVersion = '1.1';

    /**
     * @var string
     */
    private $httpMethod = 'GET';

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var array
     */
    private $queryParams = [];

    /**
     * @var array
     */
    private $formData = [];

    /**
     * @var string
     */
    private $body = '';

    /**
     * @var UploadedFile[]
     */
    private $uploadedFiles = [];

    /**
     * @var string|float
     */
    private $execStart;

    /**
     * @var Token|null
     */
    private $jwt = null;

    /**
     * @var array
     */
    private $serverParams = [];

    /**
     * @var array
     */
    private $cookieParams = [];

    /**
     * @var array
     */
    private $contextParams = [];

    private function __construct($swooleHttpRequest = null)
    {
        if (Swoole::isSwooleHttpRequest($swooleHttpRequest)) {
            $this->swooleHttpRequest = $swooleHttpRequest;
        }

        $this->execStart = microtime(true);
        $this->buildServerParams();
        $this->buildCookieParams();
        $this->buildProtocolVersion();
        $this->buildHttpMethod();
        $this->buildHttpHeaders();
        $this->buildQueryParams();
        $this->buildFormData();
        $this->buildRawBody();
        $this->buildUploadedFiles();
        $this->buildJwt();
    }

    public static function create($swooleHttpRequest = null): Request
    {
        return new self($swooleHttpRequest);
    }

    public function withContextParam(string $name, $value): Request
    {
        $this->contextParams[$name] = $value;
        return $this;
    }

    public function getContextParam(string $name)
    {
        return $this->contextParams[$name];
    }

    public function getProtocolVersion(): string
    {
        return $this->protocolVersion;
    }

    public function getHeaders(): array
    {
        return $this->headers;
    }

    public function getHeader(string $name): string
    {
        foreach ($this->headers as $headerName => $headerValue) {
            if (StringUtils::equals($headerName, $name, true)) {
                return $headerValue;
            }
        }

        return '';
    }

    public function getMethod(): string
    {
        return $this->httpMethod;
    }

    public function getServerParams(): array
    {
        return $this->serverParams;
    }

    /**
     * @param string $name
     * @return mixed
     */
    public function getServerParam(string $name)
    {
       foreach ($this->serverParams as $key => $value) {
            if (StringUtils::equals($key, $name, true)) {
                return $value;
            }
        }

        return '';
    }

    public function getCookieParams(): array
    {
        return $this->cookieParams;
    }

    public function getQueryParams(): array
    {
        return $this->queryParams;
    }

    public function getUploadedFile(string $key): ?UploadedFile
    {
        $files = $this->uploadedFiles;
        $matched = null;

        foreach ($files as $entry) {
            if ($entry->getFormFieldName() === $key) {
                $matched = $entry;
                break;
            }
        }

        return $matched instanceof UploadedFile ? $matched : null;
    }

    public function getUploadedFiles(): array
    {
        return $this->uploadedFiles;
    }

    public function getRawBody(): string
    {
        return $this->body;
    }

    public function getMap($rules = []): array
    {
        if (is_string($rules)) {
            $rules = $rules === '' ? [] : preg_split(Regexp::COMMA_SEP, $rules);
        } else if (!ArrayUtils::isStringArray($rules)) {
            $rules = [];
        }

        $isGet = strtoupper($this->getMethod()) === 'GET';
        $contentType = $this->getHeader('Content-Type');
        $isJsonPayload = stripos($contentType, 'application/json') !== false;

        $isXmlPayload = stripos($contentType, 'application/xml') !== false ||
            stripos($contentType, 'text/xml') !== false;

        if ($isGet) {
            $map1 = $this->getQueryParams();
        } else if ($isJsonPayload) {
            $map1 = JsonUtils::mapFrom($this->getRawBody());
        } else if ($isXmlPayload) {
            $map1 = StringUtils::xml2assocArray($this->getRawBody());
        } else {
            $map1 = array_merge($this->getQueryParams(), $this->getFormData());
        }

        if (!is_array($map1) || empty($map1)) {
            return [];
        }

        if (!is_array($rules) || empty($rules)) {
            return $map1;
        }

        return ArrayUtils::requestParams($map1, $rules);
    }

    /**
     * @return array|object|null
     */
    public function getParsedBody()
    {
        $contentType = $this->headers['Content-Type'];

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false ||
            stripos($contentType, 'multipart/form-data') !== false) {
            return $this->formData;
        }

        $rawBody = $this->body;

        if ($rawBody === '') {
            return null;
        }

        if (stripos($contentType, 'application/json') !== false) {
            $data = json_decode($rawBody, true);
            return is_array($data) || is_object($data) ? $data : null;
        }

        if (stripos($contentType, 'application/xml') !== false || stripos($contentType, 'text/xml') !== false) {
            return StringUtils::xml2assocArray($rawBody);
        }

        return null;
    }

    public function getRequestUrl(bool $withQueryString = false): string
    {
        $requestUri = $this->getServerParam('REQUEST_URI');

        if (!is_string($requestUri) || empty($requestUri)) {
            return '';
        }

        $requestUri = trim($requestUri, '/');
        $requestUri = StringUtils::ensureLeft($requestUri, '/');

        if (!$withQueryString) {
            return $requestUri;
        }

        $queryString = $this->getQueryString();
        return empty($queryString) ? $requestUri : "$requestUri?$queryString";
    }

    public function getQueryString(bool $urlencode = false): string
    {
        if (empty($this->queryParams)) {
            return '';
        }

        if ($urlencode) {
            return http_build_query($this->queryParams);
        }

        $sb = [];

        foreach ($this->queryParams as $key => $value) {
            $sb[] = "$key=$value";
        }

        return implode('&', $sb);
    }

    public function getClientIp(): string
    {
        $ip = $this->getHeader('X-Forwarded-For');

        if (empty($ip)) {
            $ip = $this->getHeader('X-Real-IP');
        }

        if (empty($ip)) {
            $ip = $this->getServerParam('REMOTE_ADDR');
        }

        if (!is_string($ip) || empty($ip)) {
            return '';
        }

        $parts = preg_split(Regexp::COMMA_SEP, trim($ip));
        return is_array($parts) && !empty($parts) ? trim($parts[0]) : '';
    }

    public function getFormData(): array
    {
        return $this->formData;
    }

    public function jwtIntCliam(string $name, $default = PHP_INT_MIN): int
    {
        $dv = PHP_INT_MIN;
        $n1 = Cast::toInt($default);

        if ($n1 !== PHP_INT_MIN) {
            $dv = $n1;
        }

        return $this->jwt === null ? $dv : JwtUtils::intClaim($this->jwt, $name, $dv);
    }

    public function jwtFloatClaim(string $name, float $default = PHP_FLOAT_MIN): float
    {
        $dv = PHP_FLOAT_MIN;
        $n1 = Cast::toFloat($default);

        if ($n1 !== PHP_FLOAT_MIN) {
            $dv = $n1;
        }

        return $this->jwt === null ? $dv : JwtUtils::floatClaim($this->jwt, $name, $dv);
    }

    public function jwtBooleanClaim(string $name, $default = false): bool
    {
        $dv = Cast::toBoolean($default);
        return $this->jwt === null ? $dv : JwtUtils::booleanClaim($this->jwt, $name, $dv);
    }

    public function jwtStringClaim(string $name, $default = ''): string
    {
        $dv = Cast::toString($default);
        return $this->jwt === null ? $dv : JwtUtils::stringClaim($this->jwt, $name, $dv);
    }

    public function jwtArrayClaim(string $name): array
    {
        return $this->jwt === null ? [] : JwtUtils::arrayClaim($this->jwt, $name);
    }

    public function pathVariableAsInt(string $name, $default = PHP_INT_MIN): int
    {
        $dv = PHP_INT_MIN;
        $n1 = Cast::toInt($default);

        if ($n1 !== PHP_INT_MIN) {
            $dv = $n1;
        }

        $map1 = $this->getPathVariables();
        return Cast::toInt($map1[$name], $dv);
    }

    public function pathVariableAsFloat(string $name, $default = PHP_FLOAT_MIN): float
    {
        $dv = PHP_FLOAT_MIN;
        $n1 = Cast::toFloat($default);

        if ($n1 !== PHP_FLOAT_MIN) {
            $dv = $n1;
        }

        $map1 = $this->getPathVariables();
        return Cast::toFloat($map1[$name], $dv);
    }

    public function pathVariableAsBoolean(string $name, $default = false): bool
    {
        $dv = Cast::toBoolean($default);
        $map1 = $this->getPathVariables();
        return Cast::toBoolean($map1[$name], $dv);
    }

    public function pathVariableAsString(string $name, $default = ''): string
    {
        $dv = Cast::toString($default);
        $map1 = $this->getPathVariables();
        return Cast::toString($map1[$name], $dv);
    }

    public function requestParamAsInt(string $name, $default = PHP_INT_MIN): int
    {
        $dv = PHP_INT_MIN;
        $n1 = Cast::toInt($default);

        if ($n1 !== PHP_INT_MIN) {
            $dv = $n1;
        }

        $map1 = array_merge($this->queryParams, $this->formData);
        return Cast::toInt($map1[$name], $dv);
    }

    public function requestParamAsFloat(string $name, $default = PHP_FLOAT_MIN): float
    {
        $dv = PHP_FLOAT_MIN;
        $n1 = Cast::toFloat($default);

        if ($n1 !== PHP_FLOAT_MIN) {
            $dv = $n1;
        }

        $map1 = array_merge($this->queryParams, $this->formData);
        return Cast::toFloat($map1[$name], $dv);
    }

    public function requestParamAsBoolean(string $name, $default = false): bool
    {
        $dv = Cast::toBoolean($default);
        $map1 = array_merge($this->queryParams, $this->formData);
        return Cast::toBoolean($map1[$name], $dv);
    }

    public function requestParamAsString(string $name, int $securityMode, $default = ''): string
    {
        $dv = Cast::toString($default);
        $map1 = array_merge($this->queryParams, $this->formData);
        $value = Cast::toString($map1[$name]);

        if ($value === '') {
            return $dv;
        }

        switch ($securityMode) {
            case SecurityMode::HTML_PURIFY:
                return HtmlPurifier::purify($value);
            case SecurityMode::STRIP_TAGS:
                return strip_tags($value);
            default:
                return $value;
        }
    }

    public function requestParamAsArray(string $name): array
    {
        $map1 = array_merge($this->queryParams, $this->formData);
        $ret = json_decode(Cast::toString($map1[$name]), true);
        return is_array($ret) ? $ret : [];
    }

    /**
     * @param string|array $rules
     * @return array
     */
    public function getRequestParams($rules): array
    {
        return ArrayUtils::requestParams(array_merge($this->queryParams, $this->formData), $rules);
    }

    public function getJwt(): ?Token
    {
        return $this->jwt;
    }

    /**
     * @return string|float
     */
    public function getExecStart()
    {
        return $this->execStart;
    }

    public function inSwooleMode(): bool
    {
        return Swoole::isSwooleHttpRequest($this->swooleHttpRequest);
    }

    private function buildProtocolVersion(): void
    {
        $protocol = $this->getServerParam('SERVER_PROTOCOL');

        if (!is_string($protocol) || empty($protocol)) {
            return;
        }

        $protocol = preg_replace('/[^0-9.]/', '', $protocol);
        $this->protocolVersion = strtolower($protocol);
    }

    private function buildHttpMethod(): void
    {
        $this->httpMethod = strtoupper($this->getServerParam('REQUEST_METHOD'));
    }

    private function buildHttpHeaders(): void
    {
        $swooleMode = is_object($this->swooleHttpRequest);

        if ($swooleMode) {
            try {
                $map1 = $this->swooleHttpRequest->header;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_SERVER;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || !is_string($value)) {
                continue;
            }

            $key = strtolower($key);

            if (StringUtils::startsWith($key, 'http_')) {
                $headerName = substr($key, 5);
            } else if (stripos($key, 'PHP_AUTH_DIGEST') !== false) {
                $headerName = 'authorization';
            } else {
                $headerName = $swooleMode ? $key : '';
            }

            if (empty($headerName)) {
                continue;
            }

            $headerName = preg_replace('/[\x20\t_-]+/', ' ', trim($headerName));
            $headerName = str_replace(' ', '-', ucwords($headerName));
            $this->headers[$headerName] = $value;
        }
    }

    private function buildQueryParams(): void
    {
        $req = $this->swooleHttpRequest;

        if (Swoole::isSwooleHttpRequest($req)) {
            try {
                $map1 = $req->get;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_GET;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || empty($key)) {
                continue;
            }

            $this->queryParams[$key] = Cast::toString($value);
        }
    }

    private function buildFormData(): void
    {
        $req = $this->swooleHttpRequest;

        if (Swoole::isSwooleHttpRequest($req)) {
            try {
                $map1 = $req->post;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_POST;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || empty($key)) {
                continue;
            }

            $this->formData[$key] = Cast::toString($value);
        }
    }

    private function buildRawBody(): void
    {
        $contentType = $this->getHeader('Content-Type');

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false ||
            stripos($contentType, 'multipart/form-data') !== false) {
            $this->body = empty($this->formData) ? '' : http_build_query($this->formData);
            return;
        }

        if (stripos($contentType, 'application/json') !== false ||
            stripos($contentType, 'application/xml') !== false ||
            stripos($contentType, 'text/xml') !== false) {
            $contents = $this->readRawBodyContents();
            $this->body = is_string($contents) ? $contents : '';
        }
    }

    private function readRawBodyContents(): string
    {
        $req = $this->swooleHttpRequest;

        if (Swoole::isSwooleHttpRequest($req)) {
            if (method_exists($req, 'getContent')) {
                try {
                    return Cast::toString($req->getContent());
                } catch (Throwable $ex) {
                    return '';
                }
            }

            if (method_exists($req, 'rawContent')) {
                try {
                    return Cast::toString($req->rawContent());
                } catch (Throwable $ex) {
                    return '';
                }
            }

            return '';
        }

        return Cast::toString(file_get_contents('php://input'));
    }

    private function buildUploadedFiles(): void
    {
        $req = $this->swooleHttpRequest;

        if (Swoole::isSwooleHttpRequest($req)) {
            try {
                $map1 = $req->files;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_FILES;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || empty($key) || !is_array($value) || empty($value)) {
                continue;
            }

            $name = Cast::toString($value['name']);

            if (empty($name)) {
                continue;
            }

            $meta = [
                'name' => $name,
                'type' => Cast::toString($value['type']),
                'size' => Cast::toInt($value['size']),
                'tmp_name' => Cast::toString($value['tmp_name']),
                'error' => Cast::toInt($value['error'])
            ];

            $this->uploadedFiles[$key] = UploadedFile::create($key, $meta);
        }
    }

    private function buildJwt(): void
    {
        $token = $this->getHeader('Authorization');

        if (!is_string($token) || $token === '') {
            $this->jwt = null;
            return;
        }

        $token = preg_replace('/[\x20\t]+/', ' ', trim($token));

        if (strpos($token, ' ') !== false) {
            $token = StringUtils::substringAfterLast($token, ' ');
        }

        try {
            $jwt = (new Parser())->parse($token);
        } catch (Throwable $ex) {
            $jwt = null;
        }

        $this->jwt = $jwt;
    }

    private function buildServerParams(): void
    {
        $req = $this->swooleHttpRequest;

        if (is_object($req)) {
            try {
                $map1 = $req->server;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_SERVER;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || empty($key)) {
                continue;
            }

            $this->serverParams[strtolower($key)] = Cast::toString($value);
        }
    }

    private function buildCookieParams(): void
    {
        $req = $this->swooleHttpRequest;

        if (is_object($req)) {
            try {
                $map1 = $req->cookie;
            } catch (Throwable $ex) {
                $map1 = null;
            }
        } else {
            $map1 = $_COOKIE;
        }

        if (!is_array($map1) || empty($map1)) {
            return;
        }

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || empty($key)) {
                continue;
            }

            $this->cookieParams[$key] = Cast::toString($value);
        }
    }

    private function getPathVariables(): array
    {
        $map1 = $this->contextParams['pathVariables'];

        if (!is_array($map1)) {
            return [];
        }

        $map2 = [];

        foreach ($map1 as $key => $value) {
            if (!is_string($key) || $key === '' || !is_string($value)) {
                continue;
            }

            $map2[$key] = $value;
        }

        return $map2;
    }
}
