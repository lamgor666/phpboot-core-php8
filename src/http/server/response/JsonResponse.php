<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;
use phpboot\common\util\JsonUtils;
use phpboot\common\util\StringUtils;
use Throwable;

final class JsonResponse implements ResponsePayload
{
    /**
     * @var mixed
     */
    private $payload = null;

    private function __construct($payload = null)
    {
        if ($payload === null) {
            return;
        }

        $this->payload = $payload;
    }

    public static function withPayload($payload): self
    {
        return new self($payload);
    }

    public function getContentType(): string
    {
        return 'application/json; charset=utf-8';
    }

    /**
     * @return string|HttpError
     */
    public function getContents()
    {
        $payload = $this->payload;

        if (is_string($payload)) {
            return StringUtils::isJson($payload) ? $payload : HttpError::create(400);
        }

        if (is_array($payload)) {
            $json = JsonUtils::toJson($payload);
            return StringUtils::isJson($json) ? $json : HttpError::create(400);
        }

        if (is_object($payload)) {
            if (method_exists($payload, 'toMap')) {
                try {
                    $json = JsonUtils::toJson($payload->toMap());
                } catch (Throwable $ex) {
                    $json = '';
                }

                if (StringUtils::isJson($json)) {
                    return $json;
                }
            }

            $map1 = get_object_vars($payload);

            if (!empty($map1)) {
                $json = JsonUtils::toJson($map1);

                if (StringUtils::isJson($json)) {
                    return $json;
                }
            }
        }

        return HttpError::create(400);
    }
}
