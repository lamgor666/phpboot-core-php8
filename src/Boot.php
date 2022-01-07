<?php

namespace phpboot;

use Closure;
use phpboot\common\swoole\Swoole;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\StringUtils;
use phpboot\exception\ExceptionHandler;
use phpboot\exception\ExceptionHandlerImpl;
use phpboot\exception\HttpError;
use phpboot\exception\JwtAuthException;
use phpboot\exception\ValidateException;
use phpboot\http\middleware\Middleware;
use phpboot\http\server\Request;
use phpboot\http\server\Response;
use phpboot\http\server\response\JsonResponse;
use RuntimeException;
use Symfony\Component\Routing\Exception\MethodNotAllowedException;
use Symfony\Component\Routing\Exception\ResourceNotFoundException;
use Symfony\Component\Routing\Matcher\UrlMatcher;
use Symfony\Component\Routing\RequestContext;
use Symfony\Component\Routing\Route;
use Symfony\Component\Routing\RouteCollection;
use Throwable;

final class Boot
{
    /**
     * @var array
     */
    private static $map1 = [];

    private function __construct()
    {
    }

    public static function initRouteRulesInSwooleMode(int $workerId): void
    {
        $filepath = RouteRulesBuilder::cacheFile();

        if ($filepath === '' || !is_file($filepath)) {
            return;
        }

        try {
            $cache = include($filepath);
        } catch (Throwable $ex) {
            $cache = null;
        }

        if (!is_array($cache) || !is_array($cache['routeItems']) || !is_array($cache['handlers'])) {
            return;
        }

        foreach ($cache['routeItems'] as $route) {
            self::addRoute($workerId, $route);
        }

        foreach ($cache['handlers'] as $handlerName => $fn) {
            self::addRequestHandler($workerId, $handlerName, $fn);
            self::addControllerBean($workerId, $handlerName);
        }
    }

    public static function gzipOutputEnabled(?bool $flag = null, ?int $workerId = null): bool
    {
        if (Swoole::inCoroutineMode(true)) {
            if (!is_int($workerId)) {
                $workerId = Swoole::getWorkerId();
            }

            $key = "gzipOutputEnabled_worker$workerId";
        } else {
            $key = 'gzipOutputEnabled_noworker';
        }

        if (is_bool($flag)) {
            self::$map1[$key] = $flag;
            return false;
        }

        return self::$map1[$key] === true;
    }

    public static function withExceptionHandler(ExceptionHandler $handler): void
    {
        self::checkNecessaryExceptionHandlers();
        $key = 'exception_handlers';

        if (!is_array(self::$map1[$key])) {
            self::$map1[$key] = [$handler];
            return;
        }

        $idx = -1;

        /* @var ExceptionHandler $item */
        foreach (self::$map1[$key] as $i => $item) {
            if ($item->getExceptionClassName() === $handler->getExceptionClassName()) {
                $idx = $i;
                break;
            }
        }

        if ($idx < 0) {
            self::$map1[$key][] = $handler;
        } else {
            self::$map1[$key][$idx] = $handler;
        }
    }

    public static function withMiddleware(Middleware $middleware): void
    {
        if (self::isMiddlewareExists(get_class($middleware))) {
            return;
        }

        $key = 'middlewares';

        if (!is_array(self::$map1[$key])) {
            self::$map1[$key] = [$middleware];
        } else {
            self::$map1[$key][] = $middleware;
        }
    }

    public static function handleRequest(Request $request, Response $response): void
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response->withPayload(JsonResponse::withPayload(['code' => 200]));
            $response->send();
            return;
        }

        self::checkNecessaryExceptionHandlers();
        $response->withExceptionHandlers(self::getExceptionHandlers());
        $ctx = RequestContext::fromUri($request->getRequestUrl());
        $ctx->setMethod($request->getMethod());
        $routes = new RouteCollection();
        $workerId = Swoole::getWorkerId();
        $routeItems = [];
        $handlers = [];

        if ($workerId >= 0) {
            $rulesKey = "route_items_worker$workerId";
            $routeItems = self::$map1[$rulesKey];

            if (!is_array($routeItems)) {
                $routeItems = [];
            }

            $handlersKey = "request_handlers_worker$workerId";
            $handlers = self::$map1[$handlersKey];

            if (!is_array($handlers)) {
                $handlers = [];
            }
        } else {
            $cacheFile = RouteRulesBuilder::cacheFile();

            if ($cacheFile !== '' && is_file($cacheFile)) {
                try {
                    $cache = include(RouteRulesBuilder::cacheFile());
                } catch (Throwable $ex) {
                    $cache = [];
                }

                if (is_array($cache) && is_array($cache['routeItems'])) {
                    $routeItems = $cache['routeItems'];
                }

                if (is_array($cache) && is_array($cache['handlers'])) {
                    $handlers = $cache['handlers'];
                }
            }
        }

        /* @var Route $route */
        foreach ($routeItems as $route) {
            $handlerName = $route->getOption('handlerName');
            $routes->add($handlerName, $route);
        }

        $matcher = new UrlMatcher($routes, $ctx);

        try {
            $result = $matcher->match($request->getRequestUrl());
            $handlerFunc = $result['_route'];
            $handler = $handlers[$handlerFunc];

            if (!$handler) {
                $response->withPayload(new RuntimeException("handler not found for request uri: {$request->getRequestUrl()}"));
                $response->send();
                return;
            }

            $request->withContextParam('pathVariables', ArrayUtils::removeKeys($result, '_route'));
            $handler($request, $response);
        } catch (Throwable $ex) {
            if ($ex instanceof ResourceNotFoundException) {
                $response->withPayload(HttpError::create(404));
            } else if ($ex instanceof MethodNotAllowedException) {
                $response->withPayload(HttpError::create(405));
            } else {
                $response->withPayload($ex);
            }
        }

        $response->send();
    }

    public static function getControllerBean(string $handlerName, ?int $workerId = null)
    {
        if (!Swoole::inCoroutineMode(true)) {
            return null;
        }

        if (!is_int($workerId) || $workerId < 0) {
            $workerId = Swoole::getWorkerId();
        }

        if ($workerId < 0) {
            return null;
        }

        $key = "controllers_worker$workerId";
        $map1 = self::$map1[$key];

        if (!is_array($map1)) {
            return null;
        }

        list($clazz) = explode('@', $handlerName);

        if (!is_string($clazz) || $clazz === '') {
            return null;
        }

        $clazz = StringUtils::ensureLeft($clazz, "\\");
        $bean = $map1[$clazz];
        return is_object($bean) ? $bean : null;
    }

    private static function checkNecessaryExceptionHandlers(): void
    {
        $key = 'exception_handlers';
        $handlers = self::$map1[$key];

        if (!is_array($handlers)) {
            $handlers = [];
        }

        $classes = [
            JwtAuthException::class,
            ValidateException::class
        ];

        foreach ($classes as $clazz) {
            $found = false;

            foreach ($handlers as $handler) {
                if (strpos($handler->getExceptionClassName(), $clazz) !== false) {
                    $found = true;
                    break;
                }
            }

            if (!$found) {
                $handlers[] = ExceptionHandlerImpl::create($clazz);
            }
        }

        self::$map1[$key] = $handlers;
    }

    private static function isMiddlewareExists(string $clazz): bool
    {
        $key = 'middlewares';

        if (isset(self::$map1[$key])) {
            $middlewares = self::$map1[$key];
        } else {
            self::$map1[$key] = [];
            return false;
        }

        $clazz = StringUtils::ensureLeft($clazz, "\\");

        foreach ($middlewares as $mid) {
            if (StringUtils::ensureLeft(get_class($mid), "\\") === $clazz) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return ExceptionHandler[]
     */
    private static function getExceptionHandlers(): array
    {
        $key = 'exception_handlers';
        $handlers = self::$map1[$key];
        return is_array($handlers) ? $handlers : [];
    }

    /**
     * @return Middleware[]
     */
    public static function getMiddlewares(): array
    {
        $middlewares = self::$map1['middlewares'];
        return is_array($middlewares) ? $middlewares : [];
    }

    private static function addRoute(int $workerId, Route $route): void
    {
        if ($workerId < 0) {
            return;
        }

        $mapKey = "route_items_worker$workerId";

        if (is_array(self::$map1[$mapKey])) {
            self::$map1[$mapKey][] = $route;
        } else {
            self::$map1[$mapKey] = [$route];
        }
    }

    private static function addRequestHandler(int $workerId, string $handlerName, Closure $fn): void
    {
        if ($workerId < 0) {
            return;
        }

        $mapKey = "request_handlers_worker$workerId";

        if (is_array(self::$map1[$mapKey])) {
            self::$map1[$mapKey][$handlerName] = $fn;
        } else {
            self::$map1[$mapKey] = [$handlerName => $fn];
        }
    }

    private static function addControllerBean(int $workerId, string $handlerName): void
    {
        if ($workerId < 0) {
            return;
        }

        $key = "controllers_worker$workerId";
        list($clazz) = explode('@', $handlerName);

        if (!is_string($clazz) || $clazz === '') {
            return;
        }

        $clazz = StringUtils::ensureLeft($clazz, "\\");
        $map1 = self::$map1[$key];

        if (!is_array($map1)) {
            $map1 = [];
        }

        if (isset($map1[$clazz])) {
            return;
        }

        try {
            $bean = new $clazz();
        } catch (Throwable $ex) {
            return;
        }

        if (!is_object($bean)) {
            return;
        }

        $map1[$clazz] = $bean;
        self::$map1[$key] = $map1;
    }
}
