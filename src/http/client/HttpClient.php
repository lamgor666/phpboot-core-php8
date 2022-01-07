<?php

namespace phpboot\http\client;

use GuzzleHttp\Client;
use GuzzleHttp\Cookie\CookieJar;
use GuzzleHttp\Cookie\SetCookie;
use GuzzleHttp\Handler\CurlHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use phpboot\common\Cast;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\FileUtils;
use phpboot\common\util\StringUtils;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\StreamInterface;
use RuntimeException;
use Throwable;

final class HttpClient
{
    const HTTP_ERRORS = [
        400 => 'Bad Request',
        401 => 'Unauthorized',
        402 => 'Payment Required',
        403 => 'Forbidden',
        404 => 'Not Found',
        405 => 'Method Not Allowed',
        406 => 'Not Acceptable',
        407 => 'Proxy Authentication Required',
        408 => 'Request Timeout',
        409 => 'Conflict',
        410 => 'Gone',
        411 => 'Length Required',
        412 => 'Precondition Failed',
        413 => 'Request Entity Too Large',
        414 => 'Request-URI Too Long',
        415 => 'Unsupported Media Type',
        416 => 'Requested Range Not Satisfiable',
        417 => 'Expectation Failed',
        418 => "I'm a teapot",
        421 => 'Misdirected Request',
        422 => 'Unprocessable Entity',
        423 => 'Locked',
        424 => 'Failed Dependency',
        425 => 'Too Early',
        426 => 'Upgrade Required',
        449 => 'Retry With',
        451 => 'Unavailable For Legal Reasons',
        500 => 'Internal Server Error',
        501 => 'Not Implemented',
        502 => 'Bad Gateway',
        503 => 'Service Unavailable',
        504 => 'Gateway Timeout',
        505 => 'HTTP Version Not Supported',
        506 => 'Variant Also Negotiates',
        507 => 'Insufficient Storage',
        509 => 'Bandwidth Limit Exceeded',
        510 => 'Not Extended',
        600 => 'Unparseable Response Headers'
    ];

    /**
     * @var string
     */
    private $requestUrl;

    /**
     * @var string
     */
    private $method;

    /**
     * @var int
     */
    private $connectTimeout = 3;

    /**
     * @var int
     */
    private $readTimeout = 10;

    /**
     * @var array
     */
    private $headers = [];

    /**
     * @var array
     */
    private $cookies = [];

    /**
     * @var bool
     */
    private $verify = false;

    /**
     * @var string
     */
    private $sslCertPem = '';

    /**
     * @var string
     */
    private $sslKeyPem = '';

    /**
     * @var array
     */
    private $formData = [];

    /**
     * @var array
     */
    private $formFiles = [];

    /**
     * @var string
     */
    private $payload = '';

    private function __construct(string $requestUrl)
    {
        $this->requestUrl = $requestUrl;
    }

    private function __clone()
    {
    }

    public static function create(string $requestUrl): self
    {
        return new self($requestUrl);
    }

    /**
     * @param int|string $timeout
     * @return HttpClient
     */
    public function withConnectTimeout($timeout): self
    {
        if (is_string($timeout)) {
            $timeout = StringUtils::toDuration($timeout);
        }

        if ($timeout > 0) {
            $this->connectTimeout = $timeout;
        }

        return $this;
    }

    /**
     * @param int|string $timeout
     * @return HttpClient
     */
    public function withReadTimeout($timeout): self
    {
        if (is_string($timeout)) {
            $timeout = StringUtils::toDuration($timeout);
        }

        if ($timeout > 0) {
            $this->readTimeout = $timeout;
        }

        return $this;
    }

    public function withHeader(string $headerName, string $headerValue, bool $ucwords = true): self
    {
        if ($ucwords) {
            $headerName = StringUtils::ucwords($headerName, '-', '_', '-');
        }

        if ($headerName !== '' && $headerValue !== '') {
            $this->headers[$headerName] = $headerValue;
        }

        return $this;
    }

    public function withHeaders(array $headers, bool $ucwords = true): self
    {
        foreach ($headers as $headerName => $headerValue) {
            if (!is_string($headerName) || $headerName === '') {
                continue;
            }

            if (!is_string($headerValue)) {
                $headerValue = Cast::toString($headerValue);
            }

            if ($headerValue === '') {
                continue;
            }

            $this->withHeader($headerName, $headerValue, $ucwords);
        }

        return $this;
    }

    public function withCookie(string $cookieName, string $cookieValue): self
    {
        if ($cookieName !== '') {
            $this->cookies[$cookieName] = $cookieValue;
        }

        return $this;
    }

    public function withCookies(array $cookies): self
    {
        foreach ($cookies as $cookieName => $cookieValue) {
            if (!is_string($cookieName) || $cookieName === '') {
                continue;
            }

            if (!is_string($cookieValue)) {
                $cookieValue = Cast::toString($cookieValue);
            }

            $this->withCookie($cookieName, $cookieValue);
        }

        return $this;
    }

    public function withCert(string $certPem, string $keyPem, bool $verify = false): self
    {
        $this->verify = $verify;
        $this->sslCertPem = FileUtils::getRealpath($certPem);
        $this->sslKeyPem = FileUtils::getRealpath($keyPem);
        return $this;
    }

    /**
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function get(?int $responseType = null)
    {
        $this->method = 'GET';
        $response = $this->sendRequest();
        return $responseType === ResponseType::STREAM ? $response->getBody() : $response->getBody()->getContents();
    }

    /**
     * @param array|null $foramData
     * @param array|null $formFiles
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function post(?array $foramData = null, ?array $formFiles = null, ?int $responseType = null)
    {
        $this->method = 'POST';
        $this->formData = is_array($foramData) ? $foramData : [];
        $this->formFiles = is_array($formFiles) ? $formFiles : [];
        $response = $this->sendRequest();
        return $responseType === ResponseType::STREAM ? $response->getBody() : $response->getBody()->getContents();
    }

    /**
     * @param string $xml
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function postWithXmlPayload(string $xml, ?int $responseType = null)
    {
        return $this->requestWithRawBody('POST', 'application/xml', $xml, $responseType);
    }

    /**
     * @param string $json
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function postWithJsonPayload(string $json, ?int $responseType = null)
    {
        return $this->requestWithRawBody('POST', 'application/json', $json, $responseType);
    }

    /**
     * @param string $contentType
     * @param string $contents
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function postWithRawBody(string $contentType, string $contents, ?int $responseType = null)
    {
        return $this->requestWithRawBody('POST', $contentType, $contents, $responseType);
    }

    /**
     * @param string $xml
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function putWithXmlPayload(string $xml, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PUT', 'application/xml', $xml, $responseType);
    }

    /**
     * @param string $json
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function putWithJsonPayload(string $json, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PUT', 'application/json', $json, $responseType);
    }

    /**
     * @param string $contentType
     * @param string $contents
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function putWithRawBody(string $contentType, string $contents, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PUT', $contentType, $contents, $responseType);
    }

    /**
     * @param string $xml
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function patchWithXmlPayload(string $xml, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PATCH', 'application/xml', $xml, $responseType);
    }

    /**
     * @param string $json
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function patchWithJsonPayload(string $json, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PATCH', 'application/json', $json, $responseType);
    }

    /**
     * @param string $contentType
     * @param string $contents
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function patchWithRawBody(string $contentType, string $contents, ?int $responseType = null)
    {
        return $this->requestWithRawBody('PATCH', $contentType, $contents, $responseType);
    }

    /**
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function delete(?int $responseType = null)
    {
        $this->method = 'DELETE';
        $response = $this->sendRequest();
        return $responseType === ResponseType::STREAM ? $response->getBody() : $response->getBody()->getContents();
    }

    /**
     * @param string $httpMethod
     * @param string $contentType
     * @param string $contents
     * @param int|null $responseType
     * @return StreamInterface|string
     */
    public function requestWithRawBody(
        string $httpMethod,
        string $contentType,
        string $contents,
        ?int $responseType = null
    )
    {
        $this->method = strtoupper($httpMethod);
        $this->withHeader('Content-Type', $contentType);
        $this->payload = $contents;
        $response = $this->sendRequest();
        return $responseType === ResponseType::STREAM ? $response->getBody() : $response->getBody()->getContents();
    }

    private function sendRequest(): ResponseInterface
    {
        $client = $this->buildClient();

        if ($client instanceof Client) {
            $options = $this->buildRequestOptionsForGuzzleHttpClient();

            try {
                return $client->request($this->method, $this->requestUrl, $options);
            } catch (Throwable $ex) {
                throw new RuntimeException($ex->getMessage());
            }
        }

        /* @var \Swoole\Coroutine\Http\Client $client */
        $client->set($this->buildRequestOptionsForSwooleHttpClient());
        $client->setMethod($this->method);

        if (!empty($this->cookies)) {
            $client->setCookies($this->cookies);
        }

        if (!empty($this->formFiles)) {
            if (!empty($this->headers)) {
                $client->setHeaders($this->headers);
            }

            $map1 = $this->buildFormParamsForSwooleHttpClient();

            if (!empty($map1)) {
                $client->setData($map1);
            }

            $isMultipartForm = false;

            foreach ($this->buildFormFilesForSwooleHttpClient() as $item) {
                $isMultipartForm = true;
                list($formFieldName, $source, $clientFilename, $mimeType) = $item;

                if ($source instanceof StreamInterface) {
                    $client->addData($source->getContents(), $formFieldName, $mimeType, $clientFilename);
                } else {
                    $client->addFile($source, $formFieldName, $mimeType, $clientFilename);
                }
            }

            if (!$isMultipartForm) {
                $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
                $client->setHeaders($this->headers);
            }
        } else if (!empty($this->formData)) {
            $this->withHeader('Content-Type', 'application/x-www-form-urlencoded');
            $client->setHeaders($this->headers);
            $map1 = $this->buildFormParamsForSwooleHttpClient();

            if (!empty($map1)) {
                $client->setData($map1);
            }
        } else if (!empty($this->payload)) {
            if (!empty($this->headers)) {
                $client->setHeaders($this->headers);
            }

            $client->setData($this->payload);
        } else {
            if (!empty($this->headers)) {
                $client->setHeaders($this->headers);
            }
        }

        $client->execute($this->getPathByRequestUrl());
        $statusCode = $client->getStatusCode();

        if (!is_int($statusCode)) {
            throw new RuntimeException('http client error: unknown http status code');
        }

        if ($statusCode < 0 || $statusCode >= 400) {
            $this->handleHttpException($client, $statusCode);
        }

        $contents = $client->getBody();

        if (!is_string($contents)) {
            $contents = '';
        }

        return new Response(200, [], $contents);
    }

    /**
     * @return Client|\Swoole\Coroutine\Http\Client
     */
    private function buildClient()
    {
        if (!Swoole::inCoroutineMode()) {
            return new Client(['handler' => HandlerStack::create(new CurlHandler())]);
        }

        $host = $this->getHostByRequestUrl();
        $port = $this->getPortByRequestUrl();
        $isHttps = StringUtils::startsWith($this->requestUrl, 'https://');
        return new \Swoole\Coroutine\Http\Client($host, $port, $isHttps);
    }

    private function buildRequestOptionsForGuzzleHttpClient(): array
    {
        $options = [
            'connection_timeout' => $this->connectTimeout,
            'timeout' => $this->readTimeout
        ];

        if (!empty($this->cookies)) {
            $options['cookies'] = new CookieJar(false, [SetCookie::fromString(implode('; ', $this->cookies))]);
        }

        if (StringUtils::startsWith($this->requestUrl, 'https://')) {
            $options['verify'] = $this->verify ? __DIR__ . '/cacert.pem' : false;
            $sslCertPem = $this->sslCertPem;
            $sslKeyPem = $this->sslKeyPem;

            if ($sslCertPem !== '' && is_file($sslCertPem) && $sslKeyPem !== '' && is_file($sslKeyPem)) {
                $options['cert'] = $sslCertPem;
                $options['ssl_key'] = $sslKeyPem;
            }
        }

        if (!empty($this->formFiles)) {
            $options['multipart'] = $this->buildMultipartForGuzzleHttpClient();
            $options['headers'] = $this->headers;
            return $options;
        }

        if (!empty($this->formData)) {
            $formParams = $this->buildFormParamsForGuzzleHttpClient();

            if (!empty($formParams)) {
                $options['form_params'] = $formParams;
            }

            $options['headers'] = $this->headers;
            return $options;
        }

        if ($this->payload !== '') {
            $options['body'] = $this->payload;
        }

        if (!empty($this->headers)) {
            $options['headers'] = $this->headers;
        }

        return $options;
    }

    private function buildMultipartForGuzzleHttpClient(): array
    {
        $parts = [];

        foreach ($this->formData as $name => $contents) {
            if (!is_string($name) || $name === '') {
                continue;
            }

            $contents = Cast::toString($contents);
            $parts[] = compact('name', 'contents');
        }

        /* @var FormFile $item */
        foreach ($this->formFiles as $item) {
            if (!($item instanceof FormFile)) {
                continue;
            }

            $name = $item->getFormFieldName();
            $filename = '';
            $contents = null;
            $headers = ['Content-Type' => $item->getMimeType()];

            if ($item->getFilename() !== '') {
                $filename = basename($item->getFilename());
            } else if ($item->getTempFilepath() !== '') {
                $filename = basename($item->getTempFilepath());
            }

            if ($item->getTempFilepath() !== '' && is_file($item->getTempFilepath())) {
                $contents = fopen($item->getTempFilepath(), 'r');
            }

            if ($name === '' || $filename === '') {
                continue;
            }

            if (!is_resource($contents)) {
                continue;
            }

            $parts[] = compact('name', 'filename', 'contents', 'headers');
        }

        return $parts;
    }

    private function buildFormParamsForGuzzleHttpClient(): array
    {
        $formParams = [];

        foreach ($this->formData as $fieldName => $fieldValue) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            if (!ArrayUtils::isStringArray($fieldValue)) {
                $fieldValue = Cast::toString($fieldValue);
            }

            $formParams[$fieldName] = $fieldValue;
        }

        return $formParams;
    }

    private function buildRequestOptionsForSwooleHttpClient(): array
    {
        $options = [
            'connect_timeout' => Cast::toFloat($this->connectTimeout),
            'read_timeout' => Cast::toFloat($this->readTimeout)
        ];

        if (StringUtils::startsWith($this->requestUrl, 'https://')) {
            $options['ssl_verify_peer'] = $this->verify;

            if ($this->verify) {
                $options['ssl_allow_self_signed'] = false;
                $options['ssl_host_name'] = $this->getHostByRequestUrl();
                $options['ssl_cafile'] = __DIR__ . '/cacert.pem';
            }

            $sslCertPem = $this->sslCertPem;
            $sslKeyPem = $this->sslKeyPem;

            if ($sslCertPem !== '' && is_file($sslCertPem) && $sslKeyPem !== '' && is_file($sslKeyPem)) {
                $options['ssl_cert_file'] = $sslCertPem;
                $options['ssl_key_file'] = $sslKeyPem;
            }
        }

        return $options;
    }

    private function buildFormParamsForSwooleHttpClient(): array
    {
        $map1 = [];

        foreach ($this->formData as $fieldName => $fieldValue) {
            if (!is_string($fieldName) || $fieldName === '') {
                continue;
            }

            if (is_array($fieldValue)) {
                if (ArrayUtils::isAssocArray($fieldValue)) {
                    continue;
                }

                foreach ($fieldValue as $value) {
                    $value = Cast::toString($value);

                    if ($value === '') {
                        continue;
                    }

                    $map1[$fieldName] = $value;
                }

                continue;
            }

            if (!is_string($fieldValue)) {
                continue;
            }

            $map1[$fieldName] = $fieldValue;
        }

        return $map1;
    }

    private function buildFormFilesForSwooleHttpClient(): array
    {
        $items = [];

        /* @var FormFile $item */
        foreach ($this->formFiles as $item) {
            if (!($item instanceof FormFile)) {
                continue;
            }

            $name = $item->getFormFieldName();

            if ($name === '') {
                continue;
            }

            $source = '';

            if ($item->getTempFilepath() !== '' && is_file($item->getTempFilepath())) {
                $source = $item->getTempFilepath();
            }

            if ($source === '') {
                continue;
            }

            $filename = '';

            if ($item->getFilename() !== '') {
                $filename = basename($item->getFilename());
            } else if ($item->getTempFilepath() !== '') {
                $filename = basename($item->getTempFilepath());
            }

            if (!is_string($filename) || $filename === '') {
                continue;
            }

            $mimeType = $item->getMimeType();
            $items[] = [$name, $source, $filename, $mimeType];
        }

        return $items;
    }

    private function getHostByRequestUrl(): string
    {
        $host = parse_url($this->requestUrl, PHP_URL_HOST);
        return is_string($host) ? $host : '';
    }

    private function getPortByRequestUrl(): int
    {
        $map1 = parse_url($this->requestUrl);

        if (is_array($map1) && is_int($map1['port'])) {
            return $map1['port'];
        }

        return StringUtils::startsWith($this->requestUrl, 'https://') ? 443 : 80;
    }

    private function getPathByRequestUrl(): string
    {
        $map1 = parse_url($this->requestUrl);

        if (is_array($map1) && is_string($map1['path']) && $map1['path'] !== '') {
            $path = $map1['path'];
        } else {
            $path = '/';
        }

        if (is_array($map1) && is_string($map1['query']) && $map1['query'] !== '') {
            $path .= "?{$map1['query']}";
        }

        return $path;
    }

    private function handleHttpException(\Swoole\Coroutine\Http\Client $client, int $statusCode): void
    {
        switch ($statusCode) {
            case -1:
                if ($client->errCode === 0) {
                    $error = 'connect failed';
                } else {
                    $error = socket_strerror($client->errCode);
                }

                throw new RuntimeException("http client error: $error");
            case -2:
                throw new RuntimeException('http client error: request timeout');
            case -3:
                throw new RuntimeException('http client error: server reset');
            case -4:
                throw new RuntimeException('http client error: send failed');
            default:
                $reason = Cast::toString(self::HTTP_ERRORS[$statusCode]);

                if ($reason === '') {
                    $errorTips = 'http client error: unknown http error';
                } else {
                    $errorTips = "http client error $statusCode: $reason";
                }

                throw new RuntimeException($errorTips);
        }
    }
}
