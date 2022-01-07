<?php

namespace phpboot\exception;

use phpboot\common\constant\JwtVerifyErrno;
use phpboot\common\util\JsonUtils;
use phpboot\http\server\response\JsonResponse;
use phpboot\http\server\response\ResponsePayload;
use Throwable;

final class ExceptionHandlerImpl implements ExceptionHandler
{
    /**
     * @var string
     */
    private $clazz;

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
                    switch ($ex->getErrno()) {
                        case JwtVerifyErrno::INVALID:
                            $code = 1002;
                            break;
                        case JwtVerifyErrno::EXPIRED:
                            $code = 1003;
                            break;
                        default:
                            $code = 1001;
                            break;
                    }

                    $msg = $ex->getMessage();

                    if ($msg === '') {
                        switch ($code) {
                            case 1002:
                                $msg = '不是有效的安全令牌';
                                break;
                            case 1003:
                                $msg = '安全令牌已失效';
                                break;
                            default:
                                $msg = '安全令牌缺失';
                                break;
                        }
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
