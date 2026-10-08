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

    public function __construct(private readonly DivanRepository $store) {}

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

        $entry = $this->entry($filename, $mime, (int) $file->getSize(), $now);
        $this->store->saveMediaName($filename, $entry['name']);

        return $entry;
    }

    public function all(): array
    {
        $disk = Storage::disk('news_media');
        $items = [];
        foreach ($disk->files() as $path) {
            $mime = $disk->mimeType($path);
            if (! isset(self::VIDEO_TYPES[$mime]) && ! in_array($mime, array_column(self::IMAGE_TYPES, 0), true)) {
                continue;
            }
            $entry = $this->entry($path, $mime, (int) $disk->size($path), (int) $disk->lastModified($path));
            $entry['name'] = $this->store->mediaName($path) ?? $entry['name'];
            $entry['type'] = isset(self::VIDEO_TYPES[$mime]) ? 'video' : 'image';
            $items[] = $entry;
        }

        usort($items, static fn ($a, $b) => strcmp($b['created_at'], $a['created_at']));

        return $items;
    }

    public function rename($filename, $name): array
    {
        $path = $this->safeFilename($filename);
        $name = trim($name);
        if ($name === '' || mb_strlen($name) > 120 || ! preg_match('//u', $name) || preg_match('/[\x00-\x1F\x7F]/u', $name)) {
            throw new ApiError(422, 'invalid_media_name', 'نام رسانه باید بین ۱ تا ۱۲۰ نویسه باشد.');
        }
        $disk = Storage::disk('news_media');
        if (! $disk->exists($path)) {
            throw new ApiError(404, 'media_not_found', 'فایل رسانه پیدا نشد.');
        }
        $this->store->saveMediaName($path, $name);

        return array_merge($this->entry($path, $disk->mimeType($path), (int) $disk->size($path), (int) $disk->lastModified($path)), ['name' => $name, 'type' => str_starts_with($disk->mimeType($path), 'video/') ? 'video' : 'image']);
    }

    public function delete($filename): void
    {
        $path = $this->safeFilename($filename);
        $disk = Storage::disk('news_media');
        if (! $disk->exists($path)) {
            throw new ApiError(404, 'media_not_found', 'فایل رسانه پیدا نشد.');
        }
        $disk->delete($path);
        $this->store->deleteMediaName($path);
    }

    private function safeFilename($filename): string
    {
        if (! is_string($filename) || $filename === '' || basename($filename) !== $filename || ! preg_match('/^[\pL\pN_-]+\.(?:jpe?g|png|gif|webp|mp4|webm)$/iu', $filename)) {
            throw new ApiError(422, 'invalid_media_id', 'شناسهٔ فایل رسانه معتبر نیست.');
        }

        return $filename;
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
