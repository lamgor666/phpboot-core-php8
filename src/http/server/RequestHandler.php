<?php

namespace phpboot\http\server;

use phpboot\common\util\StringUtils;
use phpboot\exception\HttpError;
use phpboot\http\middleware\MidValidate;
use phpboot\http\middleware\MidExecuteTimeLog;
use phpboot\http\middleware\MidJwtAuth;
use phpboot\http\middleware\Middleware;
use phpboot\http\middleware\MidRequestLog;
use phpboot\logging\LogContext;
use phpboot\Boot;
use phpboot\mvc\HandlerFuncArgsInjector;
use phpboot\mvc\RoutingContext;
use Throwable;

final class RequestHandler
{
    /**
     * @var Request
     */
    private $request;

    /**
     * @var Response
     */
    private $response;

    private function __construct(Request $request, Response $response)
    {
        $this->request = $request;
        $this->response = $response;
    }

    public static function create(Request $request, Response $response): RequestHandler
    {
        return new self($request, $response);
    }

    /**
     * @param Middleware[] $middlewares
     */
    public function handleRequest(array $middlewares = []): void
    {
        Boot::withControllers();
        $request = $this->request;
        $stages = [];

        foreach ($this->getPreHandleMiddlewares($request, $middlewares) as $mid) {
            $stages[] = function (RoutingContext $rc) use ($mid) {
                $mid->preHandle($rc);
            };
        }

        $routeRule = $request->getRouteRule();

        $stages[] = function (RoutingContext $rc) use ($routeRule) {
            if (!$rc->next()) {
                return;
            }

            list($clazz, $methodName) = explode('@', $routeRule->getHandler());
            $clazz = StringUtils::ensureLeft($clazz, "\\");
            $bean = Boot::getControllerBean($clazz);

            if (!is_object($bean)) {
                try {
                    $bean = new $clazz();
                } catch (Throwable $ex) {
                    $bean = null;
                }
            }

            if (!is_object($bean)) {
                $rc->getResponse()->withPayload(HttpError::create(400));
                $rc->next(false);
                return;
            }

            if (!method_exists($bean, $methodName)) {
                $rc->getResponse()->withPayload(HttpError::create(400));
                $rc->next(false);
                return;
            }

            try {
                $args = HandlerFuncArgsInjector::inject($rc->getRequest());
            } catch (Throwable $ex) {
                $rc->getResponse()->withPayload($ex);
                $rc->next(false);
                return;
            }

            try {
                $payload = empty($args)
                    ? call_user_func([$bean, $methodName])
                    : call_user_func([$bean, $methodName], ...$args);

                $rc->getResponse()->withPayload($payload);
            } catch (Throwable $ex) {
                $rc->getResponse()->withPayload($ex);
                $rc->next(false);
            }
        };

        foreach ($this->getPostHandleMiddlewares($middlewares) as $mid) {
            $stages[] = function (RoutingContext $rc) use ($mid) {
                $mid->preHandle($rc);
            };
        }

        $response = $this->response;
        $ctx = RoutingContext::create($request, $response);

        foreach ($stages as $stage) {
            try {
                $stage($ctx);
            } catch (Throwable $ex) {
                $response->withPayload($ex);
                break;
            }
        }

        $response->send();
    }

    /**
     * @param Request $req
     * @param Middleware[] $customMiddlewares
     * @return array
     */
    private function getPreHandleMiddlewares(Request $req, array $customMiddlewares = []): array
    {
        /* @var Middleware[] $middlewares */
        $middlewares = [];

        if (LogContext::requestLogEnabled()) {
            $middlewares[] = MidRequestLog::create();
        }

        $routeRule = $req->getRouteRule();

        if ($routeRule->getJwtSettingsKey() !== '') {
            $middlewares[] = MidJwtAuth::create();
        }

        if (!empty($routeRule->getValidateRules())) {
            $middlewares[] = MidValidate::create();
        }

        $customMiddlewares = array_filter($customMiddlewares, function ($it) {
            return $it->getType() === Middleware::PRE_HANDLE_MIDDLEWARE;
        });

        if (!empty($customMiddlewares)) {
            $customMiddlewares = array_values($customMiddlewares);
            array_push($middlewares, ...$customMiddlewares);
        }

        if (empty($middlewares)) {
            return [];
        }

        $middlewares = collect($middlewares)->sortBy(function ($it) {
            return $it->getOrder();
        }, SORT_NUMERIC);

        return array_values($middlewares->toArray());
    }

    /**
     * @param Middleware[] $customMiddlewares
     * @return array
     */
    private function getPostHandleMiddlewares(array $customMiddlewares = []): array
    {
        $middlewares = array_filter($customMiddlewares, function ($it) {
            return $it->getType() === Middleware::POST_HANDLE_MIDDLEWARE;
        });

        $middlewares = empty($middlewares) ? [] : array_values($middlewares);

        if (LogContext::executeTimeLogEnabled()) {
            $middlewares[] = MidExecuteTimeLog::create();
        }

        if (empty($middlewares)) {
            return [];
        }

        $middlewares = collect($middlewares)->sortBy(function ($it) {
            return $it->getOrder();
        }, SORT_NUMERIC);

        return array_values($middlewares->toArray());
    }
}
