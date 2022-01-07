<?php

namespace phpboot\http\client;

use phpboot\common\traits\MapAbleTrait;
use phpboot\common\util\FileUtils;

final class FormFile
{
    use MapAbleTrait;

    /**
     * @var string
     */
    private $formFieldName = '';

    /**
     * @var string
     */
    private $filename = '';

    /**
     * @var string
     */
    private $mimeType = '';

    /**
     * @var string
     */
    private $tempFilepath = '';

    private function __construct(?array $data = null)
    {
        if (empty($data)) {
            return;
        }

        $this->fromMap($data);
    }

    private function __clone()
    {
    }

    public static function fromFile(string $formFieldName, string $filepath): self
    {
        $filepath = FileUtils::getRealpath($filepath);
        $filename = basename($filepath);
        $mimeType = FileUtils::getMimeType($filepath, true);
        $tempFilepath = $filepath;
        return new self(compact('formFieldName', 'filename', 'mimeType', 'tempFilepath'));
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
    public function getFilename(): string
    {
        return $this->filename;
    }

    /**
     * @return string
     */
    public function getMimeType(): string
    {
        $mimeType = $this->mimeType;
        return empty($mimeType) ? 'application/octet-stream' : $mimeType;
    }

    /**
     * @return string
     */
    public function getTempFilepath(): string
    {
        return $this->tempFilepath;
    }
}
