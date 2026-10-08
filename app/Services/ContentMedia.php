<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class ContentMedia
{
    private const IMAGE_LIMIT = 10 * 1024 * 1024;

    private const VIDEO_LIMIT = 50 * 1024 * 1024;

    private const IMAGE_TYPES = [
        IMAGETYPE_JPEG => ['image/jpeg', 'jpg'],
        IMAGETYPE_PNG => ['image/png', 'png'],
        IMAGETYPE_GIF => ['image/gif', 'gif'],
        IMAGETYPE_WEBP => ['image/webp', 'webp'],
    ];

    private const VIDEO_TYPES = [
        'video/mp4' => 'mp4',
        'video/webm' => 'webm',
    ];

    public function upload($file, $type, $now): array
    {
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() <= 0) {
            throw new ApiError(422, 'invalid_media', 'فایل رسانه معتبر نیست.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath());
        if ($type === 'image') {
            $info = @getimagesize($file->getRealPath());
            $definition = $info ? (self::IMAGE_TYPES[$info[2]] ?? null) : null;
            if ($file->getSize() > self::IMAGE_LIMIT || ! $info || ! $definition || $mime !== $definition[0] || $info[0] * $info[1] > 40000000) {
                throw new ApiError(422, 'invalid_image', 'تصویر معتبر JPEG، PNG، GIF یا WebP تا ۱۰ مگابایت انتخاب کنید.');
            }
            [$mime, $extension] = $definition;
        } elseif ($type === 'video') {
            $extension = self::VIDEO_TYPES[$mime] ?? null;
            if ($file->getSize() > self::VIDEO_LIMIT || ! $extension) {
                throw new ApiError(422, 'invalid_video', 'ویدیوی MP4 یا WebM حداکثر ۵۰ مگابایت انتخاب کنید.');
            }
        } else {
            throw new ApiError(422, 'invalid_media_type', 'نوع فایل پشتیبانی نمی‌شود.');
        }

        $original = pathinfo(basename($file->getClientOriginalName()), PATHINFO_FILENAME);
        $label = preg_replace('/[^\pL\pN_-]+/u', '-', trim($original));
        $label = trim(mb_substr($label ?: '', 0, 70), '-_');
        if ($label === '') {
            $label = $type === 'video' ? 'video' : 'image';
        }
        $filename = bin2hex(random_bytes(16)).'-'.$label.'.'.$extension;
        $disk = Storage::disk('news_media');
        if (! $disk->putFileAs('', $file, $filename)) {
            throw new RuntimeException('Media storage failed');
        }

        return $this->entry($filename, $mime, (int) $file->getSize(), $now);
    }

    public function videos(): array
    {
        $disk = Storage::disk('news_media');
        $items = [];
        foreach ($disk->files() as $path) {
            $mime = $disk->mimeType($path);
            if (! isset(self::VIDEO_TYPES[$mime])) {
                continue;
            }
            $items[] = $this->entry($path, $mime, (int) $disk->size($path), (int) $disk->lastModified($path));
        }

        usort($items, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $items;
    }

    private function entry($filename, $mime, $size, $timestamp): array
    {
        $name = basename($filename);
        $displayName = preg_replace('/^[a-f0-9]{32}-/', '', $name);

        return [
            'name' => $displayName,
            'url' => '/upload/news-media/'.rawurlencode($name),
            'mime_type' => $mime,
            'size_bytes' => $size,
            'created_at' => gmdate('Y-m-d\\TH:i:s\\Z', $timestamp),
        ];
    }
}
