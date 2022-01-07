<?php

namespace phpboot\security;

use phpboot\common\Cast;
use phpboot\common\util\ArrayUtils;
use phpboot\common\util\StringUtils;
use Throwable;

final class CorsSettings
{
    /**
     * @var array
     */
    private static $map1 = [];

    /**
     * @var bool
     */
    private $enabled;

    /**
     * @var string[]
     */
    private $allowedOrigins = ['*'];

    /**
     * @var string[]
     */
    private $allowedHeaders = [
        'Content-Type',
        'Content-Length',
        'Authorization',
        'Accept',
        'Accept-Encoding',
        'X-Requested-With'
    ];

    /**
     * @var string[]
     */
    private $allowedMethods = [
        'GET',
        'POST',
        'PUT',
        'PATCH',
        'DELETE',
        'OPTIONS'
    ];

    /**
     * @var bool
     */
    private $allowCredentials = false;

    /**
     * @var string[]
     */
    private $exposedHeaders = [
        'Content-Length',
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Headers',
        'Cache-Control',
        'Content-Language',
        'Content-Type'
    ];

    /**
     * @var int
     */
    private $maxAge = 0;

    private function __construct(?array $settings = null)
    {
        if (empty($settings)) {
            $this->enabled = false;
            return;
        }

        $fieldNames = ['allowedOrigins', 'allowedHeaders', 'allowedMethods', 'exposedHeaders'];

        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            $pname = strtr($key, ['-' => ' ', '_' => ' ']);
            $pname = str_replace(' ', '', ucwords($pname));
            $pname = lcfirst($pname);

            if (!property_exists($this, $pname)) {
                continue;
            }

            if (in_array($pname, $fieldNames) && !is_array($value) || empty($value) || !ArrayUtils::isStringArray($value)) {
                continue;
            }

            if ($pname === 'allowedOrigins' && in_array('*', $value)) {
                continue;
            }

            if ($pname === 'allowCredentials' && !is_bool($value)) {
                continue;
            }

            if ($pname === 'maxAge') {
                if (is_string($value)) {
                    if (StringUtils::startsWith($value, '@Duration:')) {
                        $value = trim(StringUtils::substringAfter($value, ':'));
                    }

                    $value = Cast::toDuration($value);
                }

                if (!is_int($value) || $value < 1) {
                    continue;
                }
            }

            try {
                $this->$pname = $value;
            } catch (Throwable $ex) {
            }
        }

        $this->enabled = true;
    }

    public static function create(?array $settings = null): self
    {
        return new self($settings);
    }

    public static function withSettings(CorsSettings $settings): void
    {
        $key = 'current_settings';
        self::$map1[$key] = $settings;
    }

    public static function loadCurrent(): ?CorsSettings
    {
        $key = 'current_settings';
        $settings = self::$map1[$key];
        return $settings instanceof CorsSettings ? $settings : null;
    }

    /**
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return string[]
     */
    public function getAllowedOrigins(): array
    {
        return $this->allowedOrigins;
    }

    /**
     * @return string[]
     */
    public function getAllowedHeaders(): array
    {
        return $this->allowedHeaders;
    }

    /**
     * @return string[]
     */
    public function getAllowedMethods(): array
    {
        return $this->allowedMethods;
    }

    /**
     * @return bool
     */
    public function isAllowCredentials(): bool
    {
        return $this->allowCredentials;
    }

    /**
     * @return string[]
     */
    public function getExposedHeaders(): array
    {
        return $this->exposedHeaders;
    }

    /**
     * @return int
     */
    public function getMaxAge(): int
    {
        return $this->maxAge;
    }
}
