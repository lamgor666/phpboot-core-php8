<?php
namespace phpboot;

use phpboot\annotation\ClientIp;
use phpboot\annotation\DeleteMapping;
use phpboot\annotation\GetMapping;
use phpboot\annotation\HttpHeader;
use phpboot\annotation\JwtAuth;
use phpboot\annotation\JwtClaim;
use phpboot\annotation\MapBind;
use phpboot\annotation\PatchMapping;
use phpboot\annotation\PathVariable;
use phpboot\annotation\PostMapping;
use phpboot\annotation\PutMapping;
use phpboot\annotation\RateLimit;
use phpboot\annotation\RawJwt;
use phpboot\annotation\RawReq;
use phpboot\annotation\RequestBody;
use phpboot\annotation\RequestMapping;
use phpboot\annotation\RequestParam;
use phpboot\annotation\UploadedFile;
use phpboot\annotation\Validate;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\FileUtils;
use phpboot\common\util\JsonUtils;
use phpboot\common\util\ReflectUtils;
use phpboot\common\util\StringUtils;
use phpboot\common\util\TokenizeUtils;
use ReflectionAttribute;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;
use Throwable;

final class RouteRulesBuilder {
    private static array $map1 = [];

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

    public static function buildRouteRules(
        string $baseDir = '',
        array $modules = [],
        string $cacheFile = '',
        bool $force = false
    ): void
    {
        if (empty($baseDir)) {
            $baseDir = FileUtils::getRealpath('classpath:app/controller');
        }

        if (empty($baseDir)) {
            return;
        }

        $baseDir = str_replace("\\", '/', $baseDir);
        $baseDir = rtrim($baseDir, '/');

        if (empty($baseDir) || !is_dir($baseDir)) {
            return;
        }

        if (empty($cacheFile)) {
            $cacheFile = self::cacheFile();
        }

        if (empty($cacheFile)) {
            return;
        }

        if (is_file($cacheFile) && !$force) {
            return;
        }

        if (!ArrayUtils::isStringArray($modules)) {
            $modules = ['common', 'admin', 'wxapp', 'test'];
        }

        $rules = [];

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
            } catch (Throwable) {
                $files = [];
            }

            if (!is_array($files) || empty($files)) {
                continue;
            }

            foreach ($files as $filepath) {
                $filepath = str_replace("\\", '/', $filepath);
                $filepath = StringUtils::ensureLeft($filepath, $dir);

                try {
                    $tokens = token_get_all(file_get_contents($filepath));
                    $className = TokenizeUtils::getQualifiedClassName($tokens);
                    $clazz = new ReflectionClass($className);
                } catch (Throwable) {
                    $className = '';
                    $clazz = null;
                }

                if (empty($className) || !($clazz instanceof ReflectionClass)) {
                    continue;
                }

                $ctrlAnno = ReflectUtils::getClassAnnotation($clazz, RequestMapping::class);

                try {
                    $methods = $clazz->getMethods(ReflectionMethod::IS_PUBLIC);
                } catch (Throwable) {
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
                    } catch (Throwable) {
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
        }

        if (empty($rules)) {
            return;
        }

        $sb = [
            "<?php\n",
            'return ' . var_export($rules, true) . ";\n"
        ];

        file_put_contents($cacheFile, implode('', $sb));
    }

    private static function getHandlerFuncArgs(ReflectionMethod $method): array
    {
        $params = $method->getParameters();

        try {
            $attrs = $method->getAttributes();
        } catch (Throwable) {
            $attrs = [];
        }

        $entries = [];

        foreach ($attrs as $attr) {
            $anno = self::buildAnno($attr);

            if ($anno instanceof RawReq) {
                $entries[] = ['rawReq' => true];
                continue;
            }

            if ($anno instanceof RawJwt) {
                $entries[] = ['rawJwt' => true];
                continue;
            }

            if ($anno instanceof ClientIp) {
                $entries[] = ['clientIp' => true];
                continue;
            }

            if ($anno instanceof HttpHeader) {
                $entries[] = ['httpHeaderName' => $anno->getName()];
                continue;
            }

            if ($anno instanceof PathVariable) {
                $entries[] = [
                    'pathVariableName' => empty($anno->getName()) ? '{argName}' : $anno->getName(),
                    'defaultValue' => $anno->getDefaultValue()
                ];

                continue;
            }

            if ($anno instanceof JwtClaim) {
                $entries[] = [
                    'jwtClaimName' => empty($anno->getName()) ? '{argName}' : $anno->getName(),
                    'defaultValue' => $anno->getDefaultValue()
                ];

                continue;
            }

            if ($anno instanceof RequestParam) {
                $entries[] = [
                    'reqParamName' => empty($anno->getName()) ? '{argName}' : $anno->getName(),
                    'decimal' => $anno->isDecimal(),
                    'securityMode' => $anno->getSecurityMode(),
                    'defaultValue' => $anno->getDefaultValue()
                ];

                continue;
            }

            if ($anno instanceof MapBind) {
                $entries[] = [
                    'mapBind' => true,
                    'mapBindRules' => $anno->getRules()
                ];

                continue;
            }

            if ($anno instanceof UploadedFile) {
                $entries[] = [
                    'uploadedFile' => true,
                    'uploadedFileKey' => $anno->getValue()
                ];

                continue;
            }

            if ($anno instanceof RequestBody) {
                $map1['rawBody'] = true;
                $entries[] = ['rawBody' => true];
            }
        }

        $n1 = count($entries) - 1;

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

            if ($i <= $n1) {
                $params[$i] = array_merge($map1, $entries[$i]);
            } else {
                $params[$i] = "null";
            }
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
            $attrs = $method->getAttributes();
        } catch (Throwable) {
            $attrs = [];
        }

        $extraAnnos = [];

        foreach ($attrs as $attr) {
            $anno = self::buildAnno($attr);

            if (!is_object($anno) || !method_exists($anno, '__toString')) {
                continue;
            }

            try {
                $contents = $anno->__toString();
            } catch (Throwable) {
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

    private static function buildAnno(ReflectionAttribute $attr): ?object
    {
        $className = StringUtils::ensureLeft($attr->getName(), "\\");

        try {
            $clazz = new ReflectionClass($className);
            $arguments = $attr->getArguments();

            if (is_array($arguments) && !empty($arguments)) {
                $anno = $clazz->newInstance(...$arguments);
            } else {
                $anno = $clazz->newInstance();
            }
        } catch (Throwable) {
            $anno = null;
        }

        return is_object($anno) ? $anno : null;
    }
}
