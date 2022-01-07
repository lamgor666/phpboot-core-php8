<?php

namespace phpboot\http\server;

use phpboot\exception\ExceptionHandler;
use phpboot\exception\HttpError;
use phpboot\exception\RateLimitException;
use phpboot\http\server\response\HtmlResponse;
use phpboot\http\server\response\ResponsePayload;
use phpboot\logging\LogContext;
use phpboot\Boot;
use phpboot\security\CorsSettings;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\ExceptionUtils;
use phpboot\common\util\StringUtils;
use Throwable;

final class Response
{
    const HTTP_ERRORS = [
        '400 Bad Request',
        '401 Unauthorized',
        '402 Payment Required',
        '403 Forbidden',
        '404 Not Found',
        '405 Method Not Allowed',
        '406 Not Acceptable',
        '407 Proxy Authentication Required',
        '408 Request Timeout',
        '409 Conflict',
        '410 Gone',
        '411 Length Required',
        '412 Precondition Failed',
        '413 Request Entity Too Large',
        '414 Request-URI Too Long',
        '415 Unsupported Media Type',
        '416 Requested Range Not Satisfiable',
        '417 Expectation Failed',
        '421 too many connections',
        '422 Unprocessable Entity',
        '423 Locked',
        '424 Failed Dependency',
        '425 Unordered Collection',
        '426 Upgrade Required',
        '429 Too Many Requests',
        '449 Retry With',
        '451 Unavailable For Legal Reasons',
        '500 Internal Server Error',
        '501 Not Implemented',
        '502 Bad Gateway',
        '503 Service Unavailable',
        '504 Gateway Timeout',
        '505 HTTP Version Not Supported',
        '506 Variant Also Negotiates',
        '507 Insufficient Storage',
        '509 Bandwidth Limit Exceeded',
        '510 Not Extended',
        '600 Unparseable Response Headers'
    ];

    /**
     * @var Request
     */
    private $req;

    /**
     * @var mixed
     */
    private $swooleHttpResponse = null;

    /**
     * @var mixed
     */
    private $payload = null;

    /**
     * @var array
     */
    private $extraHeaders = [];

    /**
     * @var ExceptionHandler[]
     */
    private $exceptionHandlers = [];

    /**
     * @var CorsSettings|null
     */
    private $corsSettings = null;

    private function __construct(Request $req, $swooleHttpResponse = null)
    {
        $this->req = $req;

        if (Swoole::isSwooleHttpResponse($swooleHttpResponse)) {
            $this->swooleHttpResponse = $swooleHttpResponse;
        }
    }

    public static function create(Request $req, $swooleHttpResponse = null): self
    {
        return new self($req, $swooleHttpResponse);
    }

    public function withPayload($payload): self
    {
        $this->payload = $payload;
        return $this;
    }

    /**
     * @return mixed
     */
    public function getPayload()
    {
        return $this->payload;
    }

    public function addExtraHeader(string $headerName, string $headerValue): self
    {
        $this->extraHeaders[$headerName] = $headerValue;
        return $this;
    }

    /**
     * @param ExceptionHandler[] $handlers
     * @return Response
     */
    public function withExceptionHandlers(array $handlers): self
    {
        $this->exceptionHandlers = $handlers;
        return $this;
    }

    public function withCorsSettings(CorsSettings $settings): self
    {
        $this->corsSettings = $settings;
        return $this;
    }

    public function send(): void
    {
        Swoole::isSwooleHttpResponse($this->swooleHttpResponse) ? $this->sendBySwoole() : $this->sendByFpm();
    }

    private function sendByFpm(): void
    {
        $protocolVersion = strtoupper($this->req->getProtocolVersion());
        list($statusCode, $headers, $contents) = $this->preSend();

        if ($statusCode >= 400) {
            $reason = $this->getErrorReason($statusCode);
            header("HTTP/$protocolVersion $statusCode $reason");
            header("Status: $statusCode $reason");
            return;
        }

        $isAttachment = StringUtils::startsWith($contents, '@attachment:');

        if ($isAttachment) {
            list($attachmentFileName, $contentLength, $contents) = $this->handleAttachmentResponseForFpm($contents);

            if (!is_string($contents) || empty($contents)) {
                $this->payload = HttpError::create(400);
                $this->sendByFpm();
                return;
            }

            $headers = array_merge($headers, $this->buildAttachmentHeaders($attachmentFileName, $contentLength));
        }

        $isImage = StringUtils::startsWith($contents, '@image:');

        if ($isImage) {
            $contents = $this->handleImageResponseForFpm($contents);

            if (empty($contents)) {
                $this->payload = HttpError::create(400);
                $this->sendByFpm();
                return;
            }
        }

        foreach ($this->extraHeaders as $headerName => $headerValue) {
            $headers[$headerName] = $headerValue;
        }

        if (!$isAttachment && !$isImage && $this->needCorsSupport()) {
            $headers = $this->addCorsSupport($headers);
        }

        header("HTTP/$protocolVersion 200 OK");
        header('Status: 200 OK');

        foreach ($headers as $headerName => $headerValue) {
            header("$headerName: $headerValue");
        }

        if ($isAttachment || $isImage) {
            echo $contents;
            return;
        }

        $gzipEnable = extension_loaded('zlib') &&
            stripos($this->req->getHeader('Accept-Encoding'), 'gzip') !== false &&
            Boot::gzipOutputEnabled();

        if (!$gzipEnable) {
            echo $contents;
            return;
        }

        header('Content-Encoding: gzip');
        ob_start('ob_gzhandler');
        echo $contents;
        ob_end_flush();
    }

    private function sendBySwoole(): void
    {
        $resp = $this->swooleHttpResponse;
        list($statusCode, $headers, $contents) = $this->preSend();

        foreach ($this->extraHeaders as $headerName => $headerValue) {
            $headers[$headerName] = $headerValue;
        }

        if ($statusCode >= 400) {
            $resp->status($statusCode);
            $resp->end('');
            return;
        }

        $isAttachment = StringUtils::startsWith($contents, '@attachment:');
        $fromFile = false;

        if ($isAttachment) {
            list($attachmentFileName, $fromFile, $contentLength, $contents) = $this->handleAttachmentResponseForSwoole($contents);

            if (!is_string($contents) || empty($contents)) {
                $this->payload = HttpError::create(400);
                $this->sendBySwoole();
                return;
            }

            $headers = array_merge($headers, $this->buildAttachmentHeaders($attachmentFileName, $contentLength));
        }

        $isImage = StringUtils::startsWith($contents, '@image:');

        if ($isImage) {
            list($fromFile, $contents) = $this->handleImageResponseForSwoole($contents);

            if (empty($contents)) {
                $this->payload = HttpError::create(400);
                $this->sendByFpm();
                return;
            }
        }

        if (($isAttachment || $isImage) && $fromFile) {
            foreach ($headers as $headerName => $headerValue) {
                $resp->header($headerName, $headerValue);
            }

            $resp->sendfile($contents);
            return;
        }

        if (!$isAttachment && !$isImage && $this->needCorsSupport()) {
            $headers = $this->addCorsSupport($headers);
        }

        foreach ($headers as $headerName => $headerValue) {
            $resp->header($headerName, $headerValue);
        }

        if (!is_string($contents)) {
            $contents = '';
        }

        $resp->end($contents);
    }

    private function preSend(): array
    {
        $status = 200;
        $headers = [];
        $contents = '';
        
        if (Swoole::isSwooleHttpResponse($this->swooleHttpResponse)) {
            $headers['X-Powered-By'] = 'lamgor666/phpboot-swoole';
        } else {
            $headers['X-Powered-By'] = 'lamgor666/phpboot';
        }
        
        $payload = $this->payload;
        
        if ($payload instanceof HttpError) {
            $status = $payload->getStatusCode();
            return [$status, $headers, $contents];
        }

        if ($payload instanceof RateLimitException) {
            $status = 429;
            $payload->addSpecifyHeaders($this);
            return [$status, $headers, $contents];
        }
        
        if ($payload instanceof ResponsePayload) {
            $contents = $payload->getContents();
            
            if ($contents instanceof HttpError) {
                $status = $contents->getStatusCode();
                $contents = '';
                return [$status, $headers, $contents];
            }
            
            $headers['Content-Type'] = $payload->getContentType();
            return [$status, $headers, $contents];
        }
        
        if ($payload instanceof Throwable) {
            return $this->handleException($payload);
        }

        $payload = HtmlResponse::withContents('unsupported response payload');
        $headers['Content-Type'] = $payload->getContentType();
        $contents = $payload->getContents();
        return [$status, $headers, $contents];
    }

    private function handleException(Throwable $ex): array
    {
        $logger = LogContext::getRuntimeLogger();
        $clazz = get_class($ex);
        $handler = null;

        foreach ($this->exceptionHandlers as $it) {
            if (strpos($it->getExceptionClassName(), $clazz) !== false) {
                $handler = $it;
                break;
            }
        }

        if ($handler instanceof ExceptionHandler) {
            $payload = $handler->handleException($ex);

            if ($payload instanceof ResponsePayload) {
                $this->payload = $payload;
                return $this->preSend();
            }

            $logger->error('bad response payload from exception handler: ' . get_class($handler));
            $this->payload = HttpError::create(500);
            return $this->preSend();
        }

        $logger->error(ExceptionUtils::getStackTrace($ex));
        $this->payload = HttpError::create(500);
        return $this->preSend();
    }

    private function handleAttachmentResponseForFpm(string $contents): array
    {
        $contents = str_replace('@attachment:', '', $contents);
        list($attachmentFilename, $contents) = explode('^^^', $contents);

        if (StringUtils::startsWith($contents, 'file://')) {
            $filepath = str_replace('file://', '', $contents);
            $contentLength = (int) filesize($filepath);
            $contents = file_get_contents($filepath);
        } else {
            $contentLength = strlen($contents);
        }

        return [$attachmentFilename, $contentLength, $contents];
    }

    private function handleAttachmentResponseForSwoole(string $contents): array
    {
        $contents = str_replace('@attachment:', '', $contents);
        list($attachmentFilename, $contents) = explode('^^^', $contents);

        if (StringUtils::startsWith($contents, 'file://')) {
            $filepath = str_replace('file://', '', $contents);
            $contentLength = (int) filesize($filepath);
            return [$attachmentFilename, true, $contentLength, $filepath];
        }

        return [$attachmentFilename, false, strlen($contents), $contents];
    }

    private function handleImageResponseForFpm(string $contents): string
    {
        $contents = str_replace('@image:', '', $contents);

        if (StringUtils::startsWith($contents, 'file://')) {
            $filepath = str_replace('file://', '', $contents);
            $contents = file_get_contents($filepath);
        }

        return is_string($contents) ? $contents : '';
    }

    private function handleImageResponseForSwoole(string $contents): array
    {
        $contents = str_replace('@image:', '', $contents);

        if (StringUtils::startsWith($contents, 'file://')) {
            $filepath = str_replace('file://', '', $contents);
            return [true, $filepath];
        }

        return [false, $contents];
    }

    private function buildAttachmentHeaders(string $attachmentFilename, int $contentLength): array
    {
        $disposition = sprintf('attachment; filename="%s"', $attachmentFilename);

        return [
            'Content-Length' => "$contentLength",
            'Content-Transfer-Encoding' => 'binary',
            'Content-Disposition' => $disposition,
            'Cache-Control' => 'no-cache, no-store, max-age=0, must-revalidate',
            'Pragma' => 'public'
        ];
    }

    private function needCorsSupport(): bool
    {
        $methods = ['PUT', 'DELETE', 'CONNECT', 'OPTIONS', 'TRACE', 'PATCH'];

        if (in_array($this->req->getMethod(), $methods)) {
            return true;
        }

        $contentType = $this->req->getHeader('Content-Type');

        if (stripos($contentType, 'application/x-www-form-urlencoded') !== false ||
            stripos($contentType, 'multipart/form-data') !== false ||
            stripos($contentType, 'text/plain') !== false) {
            return true;
        }

        foreach (array_keys($this->req->getHeaders()) as $headerName) {
            if (StringUtils::equals($headerName, 'Accept', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Accept-Language')) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Content-Language', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'DPR', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Downlink', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Save-Data', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Viewport-Widt', true)) {
                return true;
            }

            if (StringUtils::equals($headerName, 'Width', true)) {
                return true;
            }
        }

        return false;
    }

    private function addCorsSupport(array $headers): array
    {
        $settings = $this->corsSettings;

        if (!($settings instanceof CorsSettings) || !$settings->isEnabled()) {
            return $headers;
        }

        $allowedOrigins = $settings->getAllowedOrigins();

        if (empty($allowedOrigins)) {
            $allowedOrigins = '*';
        } else {
            $allowedOrigins = in_array('*', $allowedOrigins) ? '*' : implode(', ', $allowedOrigins);
        }

        $headers['Access-Control-Allow-Origin'] = $allowedOrigins;
        $allowedMethods = $settings->getAllowedMethods();

        if (!empty($allowedMethods)) {
            $headers['Access-Control-Allow-Methods'] = implode(', ', $allowedMethods);
        }

        $allowedHeaders = $settings->getAllowedHeaders();

        if (!empty($allowedHeaders)) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $allowedHeaders);
        }

        $exposedHeaders = $settings->getExposedHeaders();

        if (!empty($exposedHeaders)) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $exposedHeaders);
        }

        $maxAge = $settings->getMaxAge();

        if ($maxAge > 0) {
            $headers['Access-Control-Max-Age'] = "$maxAge";
        }

        if ($settings->isAllowCredentials()) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        return $headers;
    }

    private function getErrorReason(int $statusCode): string
    {
        foreach (self::HTTP_ERRORS as $item) {
            if (!StringUtils::startsWith($item, "$statusCode")) {
                continue;
            }

            return str_replace("$statusCode ", '', $item);
        }

        return '';
    }
}
