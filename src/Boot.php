<?php

namespace phpboot;

use Closure;
use phpboot\common\constant\ReqParamSecurityMode;
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
    private static array $map1 = [];

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
            $routeRules = include($filepath);
        } catch (Throwable) {
            $routeRules = null;
        }

        if (!is_array($routeRules)) {
            $routeRules = [];
        }

        self::$map1["route_rules_worker$workerId"] = $routeRules;

        foreach ($routeRules as $rule) {
            self::addControllerBean($workerId, $rule['handler']);
        }
    }

    public static function gzipOutputEnabled(?bool $flag = null): bool
    {
        $key = 'gzip_output_enabled';

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

    public static function handleRequest(Request $request, Response $response, string $routeRulesCacheFile = ''): void
    {
        if (strtoupper($request->getMethod()) === 'OPTIONS') {
            $response->withPayload(JsonResponse::withPayload(['code' => 200]));
            $response->send();
            return;
        }

        if (!empty($routeRulesCacheFile)) {
            $request->withContextParam('routeRulesCacheFile', $routeRulesCacheFile);
        }

        self::checkNecessaryExceptionHandlers();
        $response->withExceptionHandlers(self::getExceptionHandlers());
        $ctx = RequestContext::fromUri($request->getRequestUrl());
        $ctx->setMethod($request->getMethod());
        $routes = new RouteCollection();
        $workerId = Swoole::getWorkerId();
        $routeRules = [];

        if ($workerId >= 0) {
            $rulesKey = "route_rules_worker$workerId";
            $routeRules = self::$map1[$rulesKey];
        } else {
            $cacheFile = empty($routeRulesCacheFile) ? RouteRulesBuilder::cacheFile() : $routeRulesCacheFile;

            if ($cacheFile !== '' && is_file($cacheFile)) {
                try {
                    $routeRules = include($cacheFile);
                } catch (Throwable) {
                    $routeRules = [];
                }
            }
        }

        if (!is_array($routeRules)) {
            $routeRules = [];
        }

        foreach ($routeRules as $rule) {
            $route = new Route($rule['requestMapping']);

            if ($route['httpMethod'] === 'ALL') {
                $route->setMethods(['GET', 'POST']);
            } else {
                $route->setMethods([$rule['httpMethod']]);
            }

            $routes->add($rule['handler'], $route);
        }

        $matcher = new UrlMatcher($routes, $ctx);

        try {
            $result = $matcher->match($request->getRequestUrl());
            $handlerFunc = $result['_route'];
            $request->withContextParam('pathVariables', ArrayUtils::removeKeys($result, '_route'));
        } catch (Throwable $ex) {
            if ($ex instanceof ResourceNotFoundException) {
                $response->withPayload(HttpError::create(404));
            } else if ($ex instanceof MethodNotAllowedException) {
                $response->withPayload(HttpError::create(405));
            } else {
                $response->withPayload($ex);
            }

            $response->send();
            return;
        }

        $request->withContextParam('handlerName', $handlerFunc);
        $matchedRule = null;

        foreach ($routeRules as $rule) {
            if ($rule['handler'] === $handlerFunc) {
                $matchedRule = $rule;
                break;
            }
        }

        if (empty($matchedRule)) {
            $response->withPayload(new RuntimeException("handler not found for request uri: {$request->getRequestUrl()}"));
            $response->send();
            return;
        }

        if (isset($matchedRule['rateLimitSettings']) && !empty($matchedRule['rateLimitSettings'])) {
            $request->withContextParam('rateLimitSettings', $matchedRule['rateLimitSettings']);
        }

        if (isset($matchedRule['jwtAuthKey']) && !empty($matchedRule['jwtAuthKey'])) {
            $request->withContextParam('jwtAuthKey', $matchedRule['jwtAuthKey']);
        }

        if (isset($matchedRule['validateRules']) && !empty($matchedRule['validateRules'])) {
            $request->withContextParam('validateRules', $matchedRule['validateRules']);
        }

        if (isset($matchedRule['extraAnnos']) && !empty($matchedRule['extraAnnos'])) {
            $request->withContextParam('extraAnnos', $matchedRule['extraAnnos']);
        }

        $handler = function (Request $req, Response $resp) use ($matchedRule) {
            $middlewares = collect(Boot::getMiddlewares())
                ->filter(fn(Middleware $mid) => $mid->getType() === Middleware::PRE_HANDLE_MIDDLEWARE)
                ->sortBy(fn(Middleware $mid) => $mid->getOrder(), SORT_NUMERIC);

            /* @var Middleware $mid */
            foreach ($middlewares->toArray() as $mid) {
                $mid->handle($req, $resp);
            }

            $argList = [];

            foreach ($matchedRule['handlerFuncArgs'] as $i => $argInfo) {
                if (!is_array($argInfo)) {
                    throw new RuntimeException("fail to inject arg$i for handler: {$matchedRule['handler']}");
                }

                if ($argInfo['rawReq']) {
                    $argList[] = $req;
                    continue;
                }

                if ($argInfo['rawJwt']) {
                    $argList[] = $req->getJwt();
                    continue;
                }

                if ($argInfo['clientIp']) {
                    $argList[] = $req->getClientIp();
                    continue;
                }

                if (isset($argInfo['httpHeaderName'])) {
                    $hname = str_replace('{argName}', $argInfo['name'], $argInfo['httpHeaderName']);
                    $argList[] = $req->getHeader($hname);
                    continue;
                }

                if ($argInfo['rawBody']) {
                    $argList[] = $req->getRawBody();
                    continue;
                }

                if (isset($argInfo['uploadedFileKey'])) {
                    $argList[] = $req->getUploadedFile($argInfo['uploadedFileKey']);
                    continue;
                }

                if (isset($argInfo['pathVariableName'])) {
                    $pname = str_replace('{argName}', $argInfo['name'], $argInfo['pathVariableName']);
                    $dv = $argInfo['defaultValue'];

                    $argList[] = match ($argInfo['type']) {
                        'int' => $req->pathVariableAsInt($pname, $dv),
                        'float' => $req->pathVariableAsFloat($pname, $dv),
                        'bool' => $req->pathVariableAsBoolean($pname, $dv),
                        'string' => $req->pathVariableAsString($pname, $dv),
                        default => throw new RuntimeException("unsupported type for pathVariable: $pname")
                    };

                    continue;
                }

                if (isset($argInfo['jwtClaimName'])) {
                    $cname = str_replace('{argName}', $argInfo['name'], $argInfo['jwtClaimName']);
                    $dv = $argInfo['defaultValue'];

                    $argList[] = match ($argInfo['type']) {
                        'int' => $req->jwtIntCliam($cname, $dv),
                        'float' => $req->jwtFloatClaim($cname, $dv),
                        'bool' => $req->jwtBooleanClaim($cname, $dv),
                        'string' => $req->jwtStringClaim($cname, $dv),
                        'array' => $req->jwtArrayClaim($cname),
                        default => throw new RuntimeException("unsupported type for jwt claim: $cname")
                    };

                    continue;
                }

                if (isset($argInfo['reqParamName'])) {
                    $rname = str_replace('{argName}', $argInfo['name'], $argInfo['reqParamName']);
                    $dv = $argInfo['defaultValue'];

                    switch ($argInfo['type']) {
                        case 'int':
                            $argList[] = $req->requestParamAsInt($rname, $dv);
                            break;
                        case 'float':
                            $argList[] = $req->requestParamAsFloat($rname, $dv);
                            break;
                        case 'bool':
                            $argList[] = $req->requestParamAsBoolean($rname, $dv);
                            break;
                        case 'string':
                            if ($argInfo['decimal']) {
                                $securityMode = ReqParamSecurityMode::STRIP_TAGS;
                                $paramValue = $req->requestParamAsString($rname, $securityMode, $dv);
                                $argList[] = bcadd($paramValue, 0, 2);
                            } else {
                                $securityMode = $argInfo['securityMode'];
                                $argList[] = $req->requestParamAsString($rname, $securityMode, $dv);
                            }

                            break;
                        case 'array':
                            $argList[] = $req->requestParamAsArray($rname);
                            break;
                        default:
                            throw new RuntimeException("unsupported type for request param: $rname");
                    }

                    continue;
                }

                if ($argInfo['mapBind']) {
                    $argList[] = $req->getMap($argInfo['mapBindRules']);
                    continue;
                }

                throw new RuntimeException("fail to inject arg$i [{$argInfo['name']}] for handler: {$matchedRule['handler']}");
            }

            $className = StringUtils::substringBefore($matchedRule['handler'], '@');
            $className = StringUtils::ensureLeft($className, "\\");
            $methodName = StringUtils::substringAfter($matchedRule['handler'], '@');

            if (Swoole::inCoroutineMode(true)) {
                $bean = Boot::getControllerBean($className);
            } else {
                $bean = new $className();
            }

            $payload = call_user_func_array([$bean, $methodName], $argList);

            $middlewares = collect(Boot::getMiddlewares())
                ->filter(fn(Middleware $mid) => $mid->getType() === Middleware::POST_HANDLE_MIDDLEWARE)
                ->sortBy(fn(Middleware $mid) => $mid->getOrder(), SORT_NUMERIC);

            /* @var Middleware $mid */
            foreach ($middlewares->toArray() as $mid) {
                $mid->handle($req, $resp);
            }

            $resp->withPayload($payload);
        };

        try {
            $handler($request, $response);
        } catch (Throwable $ex) {
            $response->withPayload($ex);
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
                if (str_contains($handler->getExceptionClassName(), $clazz)) {
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
        } catch (Throwable) {
            return;
        }

        if (!is_object($bean)) {
            return;
        }

        $map1[$clazz] = $bean;
        self::$map1[$key] = $map1;
    }
}
