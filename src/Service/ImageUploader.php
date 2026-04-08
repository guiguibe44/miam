<?php

declare(strict_types=1);

namespace App\Service;

use Symfony\Component\HttpFoundation\File\UploadedFile;

class ImageUploader
{
    public function __construct(private readonly string $targetDirectory)
    {
    }

    public function upload(UploadedFile $file): string
    {
        $safeFilename = bin2hex(random_bytes(12));
        $extension = $file->guessExtension() ?: 'bin';
        $newFilename = $safeFilename.'.'.$extension;

        $file->move($this->targetDirectory, $newFilename);

        return '/uploads/recipes/'.$newFilename;
    }
}
