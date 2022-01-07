<?php

namespace phpboot\security;

use phpboot\common\Cast;
use phpboot\common\util\FileUtils;
use phpboot\common\util\StringUtils;
use Throwable;

final class JwtSettings
{
    /**
     * @var array
     */
    private static $map1 = [];

    /**
     * @var string
     */
    private $key;

    /**
     * @var string
     */
    private $issuer = '';

    /**
     * @var string
     */
    private $publicKeyPemFile = '';

    /**
     * @var string
     */
    private $privateKeyPemFile = '';

    /**
     * @var int
     */
    private $ttl = 0;

    /**
     * @var int
     */
    private $refreshTokenTtl = 0;

    private function __construct(string $key, array $settings)
    {
        $this->key = $key;
        $a1 = ['issuer', 'publicKeyPemFile', 'privateKeyPemFile'];
        $a2 = ['publicKeyPemFile', 'privateKeyPemFile'];
        $a3 = ['ttl', 'refreshTokenTtl'];

        foreach ($settings as $key => $value) {
            if (!is_string($key) || $key === '') {
                continue;
            }

            switch ($key) {
                case 'iss':
                    $pname = 'issuer';
                    break;
                case 'pubpem':
                    $pname = 'publicKeyPemFile';
                    break;
                case 'pripem':
                    $pname = 'privateKeyPemFile';
                    break;
                default:
                    $pname = strtr($key, ['-' => ' ', '_' => ' ']);
                    $pname = str_replace(' ', '', ucwords($pname));
                    $pname = lcfirst($pname);
                    break;
            }

            if (!property_exists($this, $pname)) {
                continue;
            }

            if (in_array($pname, $a1) && (!is_string($value) || $value === '')) {
                continue;
            }

            if (in_array($pname, $a2)) {
                $value = FileUtils::getRealpath($value);

                if (!is_file($value)) {
                    continue;
                }
            }

            if (in_array($pname, $a3)) {
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
    }

    public static function create(string $key, array $settings): self
    {
        return new self($key, $settings);
    }

    public static function withSettings(JwtSettings $settings): void
    {
        $key = $settings->getKey() . '_current_settings';
        self::$map1[$key] = $settings;
    }

    public static function loadCurrent(string $settingsKey): ?JwtSettings
    {
        $key = $settingsKey . '_current_settings';
        $settings = self::$map1[$key];
        return $settings instanceof JwtSettings ? $settings : null;
    }

    /**
     * @return string
     */
    public function getKey(): string
    {
        return $this->key;
    }

    /**
     * @return string
     */
    public function getIssuer(): string
    {
        return $this->issuer;
    }

    /**
     * @return string
     */
    public function getPublicKeyPemFile(): string
    {
        return $this->publicKeyPemFile;
    }

    /**
     * @return string
     */
    public function getPrivateKeyPemFile(): string
    {
        return $this->privateKeyPemFile;
    }

    /**
     * @return int
     */
    public function getTtl(): int
    {
        return $this->ttl;
    }

    /**
     * @return int
     */
    public function getRefreshTokenTtl(): int
    {
        return $this->refreshTokenTtl;
    }
}
