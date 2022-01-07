<?php

namespace phpboot\http\server;

use phpboot\common\Cast;

final class UploadedFile
{
    /**
     * @var string
     */
    private $formFieldName;

    /**
     * @var string
     */
    private $clientFilename;

    /**
     * @var string
     */
    private $clientMediaType;

    /**
     * @var string
     */
    private $tempFilePath = '';

    /**
     * @var string
     */
    private $buf = '';

    /**
     * @var int
     */
    private $error;

    private function __construct(string $formFieldName, ?array $meta)
    {
        if (empty($meta)) {
            $meta = [];
        }

        $this->formFieldName = $formFieldName;
        $this->clientFilename = Cast::toString($meta['name']);
        $this->clientMediaType = Cast::toString($meta['type']);
        $this->error = Cast::toInt($meta['error']);
        $buf = Cast::toString($meta['buf']);

        if ($buf !== '') {
            $this->buf = $buf;
            return;
        }

        $filepath = Cast::toString($meta['tmp_name']);

        if ($this->error !== UPLOAD_ERR_OK || empty($filepath) || !is_file($filepath)) {
            return;
        }

        $this->tempFilePath = $filepath;
    }

    public static function create(string $formFieldName, ?array $meta = null): self
    {
        return new self($formFieldName, $meta);
    }

    public function moveTo(string $dstPath): void
    {
        $srcPath = $this->tempFilePath;

        if ($srcPath === '' || !is_file($srcPath)) {
            return;
        }

        try {
            copy($srcPath, $dstPath);
        } finally {
            unlink($srcPath);
        }
    }

    /**
     * @return string
     */
    public function getFormFieldName(): string
    {
        return $this->formFieldName;
    }

    /**
     * @return string
     */
    public function getClientFilename(): string
    {
        return $this->clientFilename;
    }

    /**
     * @return string
     */
    public function getClientMediaType(): string
    {
        $mimeType = $this->clientMediaType;

        if ($mimeType === '') {
            $mimeType = 'application/octet-stream';
        }

        return $mimeType;
    }

    /**
     * @return string
     */
    public function getTempFilePath(): string
    {
        return $this->tempFilePath;
    }

    /**
     * @return string
     */
    public function getBuf(): string
    {
        return $this->buf;
    }

    /**
     * @return int
     */
    public function getError(): int
    {
        return $this->error;
    }
}
