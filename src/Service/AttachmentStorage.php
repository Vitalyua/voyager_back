<?php

namespace App\Service;

use App\Entity\Attachment;
use Symfony\Component\Filesystem\Filesystem;
use Symfony\Component\HttpFoundation\File\UploadedFile;

class AttachmentStorage
{
    public const int MAX_SIZE = 10 * 1024 * 1024;

    public const array ALLOWED_MIME = [
        'image/jpeg',
        'image/png',
        'image/heic',
        'image/heif',
        'application/pdf',
    ];

    private const array MIME_EXT = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        'application/pdf' => 'pdf',
    ];

    public function __construct(
        private readonly Filesystem $filesystem,
        private readonly string $uploadsDir,
    ) {
    }

    public function save(UploadedFile $file, string $awb): Attachment
    {
        $mime = (string) $file->getMimeType();

        if (!in_array($mime, self::ALLOWED_MIME, true)) {
            throw new \InvalidArgumentException(sprintf('Unsupported MIME type: %s', $mime));
        }

        if ($file->getSize() > self::MAX_SIZE) {
            throw new \InvalidArgumentException(sprintf('File exceeds size limit (%d bytes)', self::MAX_SIZE));
        }

        $name = bin2hex(random_bytes(16)) . '.' . self::MIME_EXT[$mime];

        $dir = $this->dir($awb);
        $this->filesystem->mkdir($dir, 0775);
        $file->move($dir, $name);

        return (new Attachment())
            ->setName($name)
            ->setMime($mime);
    }

    public function path(Attachment $attachment, string $awb): string
    {
        return $this->dir($awb) . '/' . $attachment->getName();
    }

    private function dir(string $awb): string
    {
        return rtrim($this->uploadsDir, '/') . '/scan/' . $awb;
    }
}