<?php

namespace phpboot\http\server\response;


use phpboot\exception\HttpError;
use phpboot\common\traits\MapAbleTrait;
use Throwable;

final class ImageResponse implements ResponsePayload
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
    private $mimeType = '';

    private function __construct(?array $data = null)
    {
        if (empty($data)) {
            return;
        }

        $this->fromMap($data);
    }

    public static function fromFile(string $filepath): self
    {
        if (empty($filepath) || !is_file($filepath)) {
            return new self();
        }

        $imageSize = getimagesize($filepath);

        if (!is_array($imageSize) || empty($imageSize)) {
            return new self();
        }

        try {
            $mimeType = image_type_to_mime_type($imageSize[2]);

            if (empty($mimeType)) {
                return new self();
            }

            return new self(compact('filepath', 'mimeType'));
        } catch (Throwable $ex) {
            return new self();
        }
    }

    public static function fromBuffer(string $contents, string $mimeType): self
    {
        $buf = $contents;
        return new self(compact('buf', 'mimeType'));
    }

    public function getContentType(): string
    {
        return $this->mimeType;
    }

    /**
     * @return string|HttpError
     */
    public function getContents()
    {
        $mimeType = $this->mimeType;

        if (empty($mimeType)) {
            return HttpError::create(400);
        }

        $buf = $this->buf;

        if ($buf !== '') {
            return "@image:$buf";
        }

        $filepath = $this->filepath;

        if ($filepath === '' || !is_file($filepath)) {
            return HttpError::create(400);
        }

        return "@image:file://$filepath";
    }
}
