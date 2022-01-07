<?php

namespace phpboot\http\server\response;

use phpboot\exception\HttpError;
use phpboot\common\traits\MapAbleTrait;
use phpboot\common\util\FileUtils;

final class AttachmentResponse implements ResponsePayload
{
    use MapAbleTrait;

    /**
     * @var string
     */
    private $filepath = '';

    /**
     * @var string
     */
    private $buf = '';

    /**
     * @var string
     */
    private $attachmentFileName = '';

    private function __construct(?array $data = null)
    {
        if (empty($data)) {
            return;
        }

        $this->fromMap($data);
    }

    public static function fromFile(string $filepath, string $attachmentFileName): self
    {
        return new self(compact('filepath', 'attachmentFileName'));
    }

    public static function fromBuffer(string $contents, string $attachmentFileName): self
    {
        $buf = $contents;
        return new self(compact('buf', 'attachmentFileName'));
    }

    public function getContentType(): string
    {
        $filepath = $this->filepath;

        if ($filepath === '' || !is_file($filepath)) {
            return 'application/octet-stream';
        }

        $mimeType = FileUtils::getMimeType($filepath, true);

        if (empty($mimeType)) {
            $mimeType = FileUtils::getMimeType($filepath);
        }

        return empty($mimeType) ? 'application/octet-stream' : $mimeType;
    }

    /**
     * @return string|HttpError
     */
    public function getContents()
    {
        $attachmentFileName = $this->attachmentFileName;

        if (empty($attachmentFileName)) {
            return HttpError::create(400);
        }

        $buf = $this->buf;

        if ($buf !== '') {
            return "@attachment:$attachmentFileName^^^$buf";
        }

        $filepath = $this->filepath;

        if ($filepath === '' || !is_file($filepath)) {
            return HttpError::create(400);
        }

        return "@attachment:$attachmentFileName^^^file://$filepath";
    }
}
