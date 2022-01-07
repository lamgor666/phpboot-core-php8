<?php

namespace phpboot\exception;

use phpboot\common\constant\JwtVerifyErrno;
use phpboot\common\util\JsonUtils;
use phpboot\http\server\response\JsonResponse;
use phpboot\http\server\response\ResponsePayload;
use Throwable;

final class ExceptionHandlerImpl implements ExceptionHandler
{
    private string $clazz;

    private function __construct(string $clazz)
    {
        $this->clazz = $clazz;
    }

    public static function create(string $clazz): self
    {
        return new self($clazz);
    }

    public function getExceptionClassName(): string
    {
        return $this->clazz;
    }

    public function handleException(Throwable $ex): ?ResponsePayload
    {
        $payload = null;

        switch (get_class($ex)) {
            case JwtAuthException::class:
                if ($ex instanceof JwtAuthException) {
                    $code = match ($ex->getErrno()) {
                        JwtVerifyErrno::INVALID => 1002,
                        JwtVerifyErrno::EXPIRED => 1003,
                        default => 1001,
                    };

                    $msg = $ex->getMessage();

                    if ($msg === '') {
                        $msg = match ($code) {
                            1002 => '不是有效的安全令牌',
                            1003 => '安全令牌已失效',
                            default => '安全令牌缺失',
                        };
                    }

                    $payload = JsonResponse::withPayload(compact('code', 'msg'));
                }

                break;
            case ValidateException::class:
                if ($ex instanceof ValidateException) {
                    if ($ex->isFailfast()) {
                        $code = 1999;
                        $msg = $ex->getMessage();
                    } else {
                        $code = 1006;
                        $validateErrors = $ex->getValidateErrors();
                        $msg = JsonUtils::toJson($validateErrors);
                    }

                    $payload = JsonResponse::withPayload(compact('code', 'msg'));
                }

                break;
        }

        return $payload;
    }
}
