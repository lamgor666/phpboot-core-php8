<?php
namespace phpboot;

use Doctrine\Common\Annotations\AnnotationReader;
use Lcobucci\JWT\Token;
use phpboot\annotation\ClientIp;
use phpboot\annotation\DeleteMapping;
use phpboot\annotation\GetMapping;
use phpboot\annotation\HttpHeader;
use phpboot\annotation\JwtAuth;
use phpboot\annotation\JwtClaim;
use phpboot\annotation\MapBind;
use phpboot\annotation\ParamInject;
use phpboot\annotation\PatchMapping;
use phpboot\annotation\PathVariable;
use phpboot\annotation\PostMapping;
use phpboot\annotation\PutMapping;
use phpboot\annotation\RateLimit;
use phpboot\annotation\RequestBody;
use phpboot\annotation\RequestMapping;
use phpboot\annotation\RequestParam;
use phpboot\annotation\UploadedFile;
use phpboot\annotation\Validate;
use phpboot\common\constant\RandomStringType;
use phpboot\common\constant\ReqParamSecurityMode;
use phpboot\common\util\FileUtils;
use phpboot\common\util\JsonUtils;
use phpboot\common\util\ReflectUtils;
use phpboot\common\util\StringUtils;
use phpboot\common\util\TokenizeUtils;
use phpboot\http\middleware\Middleware;
use phpboot\http\server\Request;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class RouteRulesBuilder {
    /**
     * @var array
     */
    private static $map1 = [];

    private function __construct()
    {
    }

    private function __clone()
    {
    }

    public static function cacheFile(?string $filepath = null): string
    {
        $key = 'route_rules_cache_file';

        if (is_string($filepath)) {
            self::$map1[$key] = FileUtils::getRealpath($filepath);
            return '';
        }

        $fpath = self::$map1[$key];
        return is_string($fpath) ? $fpath : '';
    }

    public static function buildRouteRules(string $baseDir, array $modules, bool $force = false): void
    {
        $baseDir = str_replace("\\", '/', $baseDir);
        $baseDir = rtrim($baseDir, '/');

        if ($baseDir === '' || !is_dir($baseDir)) {
            return;
        }

        $cacheFile = self::cacheFile();

        if ($cacheFile === '') {
            return;
        }

        if (is_file($cacheFile) && !$force) {
            return;
        }

        $lines = [];
        $hidx = 1;

        foreach ($modules as $mod) {
            if (!is_string($mod) || $mod === '') {
                continue;
            }

            $dir = "$baseDir/$mod";

            if (!is_dir($dir)) {
                continue;
            }

            try {
                $files = glob("$dir/*.php");
            } catch (Throwable $ex) {
                $files = [];
            }

            if (!is_array($files) || empty($files)) {
                continue;
            }

            $rules = [];

            foreach ($files as $filepath) {
                $filepath = str_replace("\\", '/', $filepath);
                $filepath = StringUtils::ensureLeft($filepath, $dir);

                try {
                    $tokens = token_get_all(file_get_contents($filepath));
                    $className = TokenizeUtils::getQualifiedClassName($tokens);
                    $clazz = new ReflectionClass($className);
                } catch (Throwable $ex) {
                    $className = '';
                    $clazz = null;
                }

                if (empty($className) || !($clazz instanceof ReflectionClass)) {
                    continue;
                }

                $ctrlAnno = ReflectUtils::getClassAnnotation($clazz, RequestMapping::class);

                try {
                    $methods = $clazz->getMethods(ReflectionMethod::IS_PUBLIC);
                } catch (Throwable $ex) {
                    $methods = [];
                }

                foreach ($methods as $method) {
                    try {
                        $map1 = [
                            'handler' => "$className@{$method->getName()}",
                            'handlerFuncArgs' => self::getHandlerFuncArgs($method),
                            'rateLimitSettings' => self::getRateLimitSettings($method),
                            'jwtAuthKey' => self::getJwtAuthKey($method),
                            'validateRules' => self::getValidateRules($method),
                            'extraAnnos' => self::getExtraAnnos($method)
                        ];
                    } catch (Throwable $ex) {
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, GetMapping::class);

                    if ($anno instanceof GetMapping) {
                        $map1['httpMethod'] = 'GET';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, PostMapping::class);

                    if ($anno instanceof PostMapping) {
                        $map1['httpMethod'] = 'POST';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, PutMapping::class);

                    if ($anno instanceof PutMapping) {
                        $map1['httpMethod'] = 'PUT';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, PatchMapping::class);

                    if ($anno instanceof PatchMapping) {
                        $map1['httpMethod'] = 'PATCH';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, DeleteMapping::class);

                    if ($anno instanceof DeleteMapping) {
                        $map1['httpMethod'] = 'DELETE';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                        continue;
                    }

                    $anno = ReflectUtils::getMethodAnnotation($method, RequestMapping::class);

                    if ($anno instanceof RequestMapping) {
                        $map1['httpMethod'] = 'ALL';
                        $map1['requestMapping'] = self::getRequestMapping($ctrlAnno, $anno->getValue());
                        $rules[] = $map1;
                    }
                }
            }

            if (empty($rules)) {
                continue;
            }

            foreach ($rules as $rule) {
                if ($rule['httpMethod'] == 'ALL') {
                    $httpMethods = "['GET', 'POST']";
                } else {
                    $httpMethods = sprintf("['%s']", $rule['httpMethod']);
                }

                array_push(
                    $lines,
                    sprintf("\$route%03d = new \\Symfony\\Component\\Routing\\Route('%s');", $hidx, $rule['requestMapping']),
                    sprintf("\$route%03d->setMethods(%s);", $hidx, $httpMethods),
                    sprintf("\$route%03d->addOptions(['handlerName' => '%s']);", $hidx, $rule['handler']),
                    sprintf("\$routeRulesCache['routeItems'][] = \$route%03d;", $hidx),
                    ''
                );

                array_push($lines, ...self::buildHandlerCodeParts($rule));
                $lines[] = '';
                $hidx++;
            }
        }

        if (empty($lines)) {
            return;
        }

        $contnets = self::getTmplContents();

        if (empty($contnets)) {
            return;
        }

        $dir = dirname($cacheFile);

        if (!is_dir($dir)) {
            mkdir($dir, 0755, true);
        }

        if (!is_dir($dir)) {
            return;
        }

        $contnets = str_replace('// autogen codes', rtrim(implode("\n", $lines)), $contnets);
        $fp = fopen($cacheFile, 'w');

        if (!is_resource($fp)) {
            return;
        }

        flock($fp, LOCK_EX);
        fwrite($fp, $contnets);
        flock($fp, LOCK_UN);
        fclose($fp);
    }

    private static function getHandlerFuncArgs(ReflectionMethod $method): array
    {
        $params = $method->getParameters();
        $anno1 = ReflectUtils::getMethodAnnotation($method, ParamInject::class);

        if ($anno1 instanceof ParamInject) {
            $injectRules = $anno1->getValue();

            if (is_array($injectRules['value'])) {
                $injectRules = $injectRules['value'];
            }
        } else {
            $injectRules = [];
        }

        $n1 = count($injectRules) - 1;

        foreach ($params as $i => $p) {
            $type = $p->getType();

            if (!($type instanceof ReflectionNamedType)) {
                $params[$i] = ['name' => $p->getName()];
                continue;
            }

            $typeName = $type->isBuiltin() ? $type->getName() : StringUtils::ensureLeft($type->getName(), "\\");

            $map1 = [
                'name' => $p->getName(),
                'type' => $typeName
            ];

            if ($type->allowsNull()) {
                $map1['nullable'] = true;
            }

            if (strpos($typeName, Request::class) !== false) {
                $map1['rawReq'] = true;
                $params[$i] = $map1;
                continue;
            }

            if (strpos($typeName, Token::class) !== false) {
                $map1['rawJwt'] = true;
                $params[$i] = $map1;
                continue;
            }

            if ($i <= $n1) {
                $anno = $injectRules[$i];

                if ($anno instanceof ClientIp) {
                    $map1['clientIp'] = true;
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof HttpHeader) {
                    $map1['httpHeaderName'] = $anno->getName();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof JwtClaim) {
                    $map1['jwtClaimName'] = empty($anno->getName()) ? $p->getName() : $anno->getName();
                    $map1['defaultValue'] = $anno->getDefaultValue();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof RequestParam) {
                    $map1['reqParamName'] = empty($anno->getName()) ? $p->getName() : $anno->getName();
                    $map1['decimal'] = $anno->isDecimal();
                    $map1['securityMode'] = $anno->getSecurityMode();
                    $map1['defaultValue'] = $anno->getDefaultValue();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof PathVariable) {
                    $map1['pathVariableName'] = empty($anno->getName()) ? $p->getName() : $anno->getName();
                    $map1['defaultValue'] = $anno->getDefaultValue();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof MapBind) {
                    $map1['mapBind'] = true;
                    $map1['mapBindRules'] = $anno->getRules();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof UploadedFile) {
                    $map1['uploadedFile'] = true;
                    $map1['uploadedFileKey'] = $anno->getValue();
                    $params[$i] = $map1;
                    continue;
                }

                if ($anno instanceof RequestBody) {
                    $map1['rawBody'] = true;
                    $params[$i] = $map1;
                    continue;
                }
            }

            $params[$i] = "null";
        }

        return $params;
    }

    private static function getRateLimitSettings(ReflectionMethod $method): string
    {
        $anno =  ReflectUtils::getMethodAnnotation($method, RateLimit::class);

        if ($anno instanceof RateLimit) {
            $total = $anno->getTotal();
            $duration = $anno->getDuration();
            $limitByIp = $anno->isLimitByIp();
            $contents = JsonUtils::toJson(compact('total', 'duration', 'limitByIp'));
            return str_replace('"', '[syh]', $contents);
        }

        return '';
    }

    private static function getJwtAuthKey(ReflectionMethod $method): string
    {
        $anno =  ReflectUtils::getMethodAnnotation($method, JwtAuth::class);
        return $anno instanceof JwtAuth ? $anno->getValue() : '';
    }

    private static function getValidateRules(ReflectionMethod $method): string
    {
        $anno = ReflectUtils::getMethodAnnotation($method, Validate::class);

        if ($anno instanceof Validate) {
            $rules = $anno->getRules();
            array_push($rules, $anno->isFailfast() ? 'true' : 'false');
            $contents = JsonUtils::toJson($rules);
            return str_replace('"', '[syh]', $contents);
        }

        return '';
    }

    private static function getExtraAnnos(ReflectionMethod $method): string
    {
        try {
            $reader = new AnnotationReader();
            $annos = $reader->getMethodAnnotations($method);
        } catch (Throwable $ex) {
            $annos = [];
        }

        $extraAnnos = [];

        foreach ($annos as $anno) {
            if (!is_object($anno) || !method_exists($anno, '__toString')) {
                continue;
            }

            try {
                $contents = $anno->__toString();
            } catch (Throwable $ex) {
                continue;
            }

            if (!is_string($contents) || $contents === '') {
                continue;
            }

            $contents = str_replace('"', '[syh]', $contents);
            $contents = str_replace("'", '[dyh]', $contents);
            $extraAnnos[] = $contents;
        }

        return implode('~@~', $extraAnnos);
    }

    private static function getRequestMapping($anno, string $requestMapping): string
    {
        $s1 = '';

        if ($anno instanceof RequestMapping) {
            $s1 = $anno->getValue();

            if ($s1 !== '' && $s1 !== '/') {
                $s1 = rtrim($s1, '/');
            }
        }

        if ($s1 === '') {
            return StringUtils::ensureLeft($requestMapping, '/');
        }

        return $s1 . StringUtils::ensureLeft($requestMapping, '/');
    }

    private static function buildHandlerCodeParts(array $rule): array
    {
        $tab1 = str_repeat(' ', 4);
        $tab2 = str_repeat(' ', 8);

        $parts = [
            sprintf("\$routeRulesCache['handlers']['%s'] = function (\$req, \$resp) {", $rule['handler']),
            sprintf("$tab1\$req->withContextParam('handlerName', '%s')", $rule['handler'])
        ];

        if ($rule['rateLimitSettings'] !== '') {
            $parts[] = sprintf("$tab1\$req->withContextParam('rateLimitSettings', '%s')", $rule['rateLimitSettings']);
        }

        if ($rule['jwtAuthKey'] !== '') {
            $parts[] = sprintf("$tab1\$req->withContextParam('jwtAuthKey', '%s')", $rule['jwtAuthKey']);
        }

        if ($rule['validateRules'] !== '') {
            $parts[] = sprintf("$tab1\$req->withContextParam('validateRules', '%s')", $rule['validateRules']);
        }

        if ($rule['extraAnnos'] !== '') {
            $parts[] = sprintf("$tab1\$req->withContextParam('extraAnnos', '%s')", $rule['extraAnnos']);
        }

        $parts = self::buildPreHandleMiddlewraesParts($parts);
        $beanClass = StringUtils::substringBefore($rule['handler'], '@');
        $beanClass = StringUtils::ensureLeft($beanClass, "\\");
        $funcName = StringUtils::substringAfter($rule['handler'], '@');

        array_push(
            $parts,
            '',
            "{$tab1}if (\\phpboot\\common\\swoole\\Swoole::inCoroutineMode(true)) {",
            sprintf("$tab2\$bean = \\phpboot\\Boot::getControllerBean('%s');", $beanClass),
            "$tab2} else {",
            sprintf("$tab2\$bean = new %s();", $beanClass),
            "$tab1}",
            ''
        );

        list($parts, $argList) = self::buildArgList($rule, $parts);
        $parts[] = sprintf("$tab1\$payload = \$bean->%s(%s);", $funcName, implode(', ', $argList));
        $parts = self::buildPostHandleMiddlewaresParts($parts);

        array_push(
            $parts,
            "$tab1\$resp->withPayload(\$payload);",
            '};',
            ''
        );

        return $parts;
    }

    private static function buildPreHandleMiddlewraesParts(array $codeParts): array
    {
        $tab1 = str_repeat(' ', 4);
        $tab2 = str_repeat(' ', 8);
        $middlewareNames = [];

        $middlewares = collect(Boot::getMiddlewares())->filter(function (Middleware $mid) {
            return $mid->getType() === Middleware::PRE_HANDLE_MIDDLEWARE;
        })->sortBy(function (Middleware $mid) {
            return $mid->getOrder();
        }, SORT_NUMERIC);

        /* @var Middleware $mid */
        foreach ($middlewares->toArray() as $mid) {
            $midClazz = StringUtils::ensureLeft(get_class($mid), "\\");
            $rnd = StringUtils::getRandomString(8, RandomStringType::ALNUM);

            if (method_exists($midClazz, 'create')) {
                $codeParts[] = sprintf("$tab1\$mid_%s = %s::create();", $rnd, $midClazz);
            } else {
                $codeParts[] = sprintf("$tab1\$mid_%s = new %s();", $rnd, $midClazz);
            }

            $middlewareNames[] = sprintf("\$mid_%s", $rnd);
        }

        if (!empty($middlewareNames)) {
            array_push(
                $codeParts,
                sprintf("$tab1\$preHandleMiddlewares = [%s];", implode(', ', $middlewareNames)),
                '',
                "{$tab1}foreach (\$preHandleMiddlewares as \$mid) {",
                "$tab2\$mid->handle(\$req, \$resp);",
                "$tab1}",
                ''
            );
        }

        return $codeParts;
    }

    private static function buildPostHandleMiddlewaresParts(array $codeParts): array
    {
        $tab1 = str_repeat(' ', 4);
        $tab2 = str_repeat(' ', 8);
        $middlewareNames = [];

        $middlewares = collect(Boot::getMiddlewares())->filter(function (Middleware $mid) {
            return $mid->getType() === Middleware::POST_HANDLE_MIDDLEWARE;
        })->sortBy(function (Middleware $mid) {
            return $mid->getOrder();
        }, SORT_NUMERIC);

        /* @var Middleware $mid */
        foreach ($middlewares->toArray() as $mid) {
            $midClazz = StringUtils::ensureLeft(get_class($mid), "\\");
            $rnd = StringUtils::getRandomString(8, RandomStringType::ALNUM);

            if (method_exists($midClazz, 'create')) {
                $codeParts[] = sprintf("$tab1\$mid_%s = %s::create();", $rnd, $midClazz);
            } else {
                $codeParts[] = sprintf("$tab1\$mid_%s = new %s();", $rnd, $midClazz);
            }

            $middlewareNames[] = sprintf("\$mid_%s", $rnd);
        }

        if (!empty($middlewareNames)) {
            array_push(
                $codeParts,
                sprintf("$tab1\$postHandleMiddlewares = [%s];", implode(', ', $middlewareNames)),
                '',
                "{$tab1}foreach (\$postHandleMiddlewares as \$mid) {",
                "$tab2\$mid->handle(\$req, \$resp);",
                "$tab1}",
                ''
            );
        }

        return $codeParts;
    }

    private static function buildArgList(array $rule, array $codeParts): array
    {
        $tab1 = str_repeat(' ', 4);
        $argList = [];

        foreach ($rule['handlerFuncArgs'] as $i => $argInfo) {
            if (!is_array($argInfo)) {
                $codeParts[] = sprintf("$tab1\$arg%d = null;", $i);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['rawReq']) {
                $codeParts[] = sprintf("$tab1\$arg%d = \$req;", $i);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['rawJwt']) {
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getJwt();", $i);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['clientIp']) {
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getClientIp();", $i);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['httpHeaderName'] !== '') {
                $hname = $argInfo['httpHeaderName'];
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getHeader('%s');", $i, $hname);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['rawBody']) {
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getRawBody();", $i);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['uploadedFileKey'] !== '') {
                $fkey = $argInfo['uploadedFileKey'];
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getUploadedFile('%s');", $i, $fkey);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['pathvariableName'] !== '') {
                $pname = $argInfo['pathvariableName'];
                $dv = $argInfo['defaultValue'];

                switch ($argInfo['type']) {
                    case 'int':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->pathVariableAsInt('%s', '%s');", $i, $pname, $dv);
                        break;
                    case 'float':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->pathVariableAsFloat('%s', '%s');", $i, $pname, $dv);
                        break;
                    case 'bool':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->pathVariableAsBoolean('%s', '%s');", $i, $pname, $dv);
                        break;
                    case 'string':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->pathVariableAsString('%s', '%s');", $i, $pname, $dv);
                        break;
                    default:
                        $codeParts[] = sprintf("$tab1\$arg%d = null;", $i);
                        break;
                }

                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['jwtClaimName'] !== '') {
                $cname = $argInfo['jwtClaimName'];
                $dv = $argInfo['defaultValue'];

                switch ($argInfo['type']) {
                    case 'int':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->jwtIntCliam('%s', '%s');", $i, $cname, $dv);
                        break;
                    case 'float':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->jwtFloatClaim('%s', '%s');", $i, $cname, $dv);
                        break;
                    case 'bool':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->jwtBooleanClaim('%s', '%s');", $i, $cname, $dv);
                        break;
                    case 'string':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->jwtStringClaim('%s', '%s');", $i, $cname, $dv);
                        break;
                    case 'array':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->jwtArrayClaim('%s');", $i, $cname);
                        break;
                    default:
                        $codeParts[] = sprintf("$tab1\$arg%d = null;", $i);
                        break;
                }

                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['reqParamName'] !== '') {
                $rname = $argInfo['reqParamName'];
                $dv = $argInfo['defaultValue'];

                switch ($argInfo['type']) {
                    case 'int':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->requestParamAsInt('%s', '%s');", $i, $rname, $dv);
                        break;
                    case 'float':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->requestParamAsFloat('%s', '%s');", $i, $rname, $dv);
                        break;
                    case 'bool':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->requestParamAsBoolean('%s', '%s');", $i, $rname, $dv);
                        break;
                    case 'string':
                        if ($argInfo['decimal']) {
                            $securityMode = ReqParamSecurityMode::STRIP_TAGS;
                            $codeParts[] = sprintf("$tab1\$arg%d = bcadd(\$req->requestParamAsString('%s', %d, '%s'), 0, 2);", $i, $rname, $securityMode, $dv);
                        } else {
                            $securityMode = $argInfo['securityMode'];
                            $codeParts[] = sprintf("$tab1\$arg%d = \$req->requestParamAsString('%s', %d, '%s');", $i, $rname, $securityMode, $dv);
                        }

                        break;
                    case 'array':
                        $codeParts[] = sprintf("$tab1\$arg%d = \$req->requestParamAsArray('%s');", $i, $rname);
                        break;
                    default:
                        $codeParts[] = sprintf("$tab1\$arg%d = null;", $i);
                        break;
                }

                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            if ($argInfo['mapBind']) {
                $mapBindRules = implode(', ', $argInfo['mapBindRules']);
                $codeParts[] = sprintf("$tab1\$arg%d = \$req->getMap('%s');", $i, $mapBindRules);
                $argList[] = sprintf("\$arg%d", $i);
                continue;
            }

            $codeParts[] = sprintf("$tab1\$arg%d = null;", $i);
            $argList[] = sprintf("\$arg%d", $i);
        }

        return [$codeParts, $argList];
    }

    private static function getTmplContents(): string
    {
        $contents = file_get_contents(__DIR__ . '/route_rules_cache.tpl');
        return is_string($contents) ? $contents : '';
    }
}
