<?php

namespace App\Services;

use App\Exceptions\ApiError;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

class CategoryImages
{
    public function save($file): string
    {
        if (! $file instanceof UploadedFile || ! $file->isValid() || $file->getSize() <= 0 || $file->getSize() > 5000000) {
            throw new ApiError(422, 'invalid_image', 'تصویر معتبر تا ۵ مگابایت انتخاب کنید.');
        }
        $info = @getimagesize($file->getRealPath());
        $types = [IMAGETYPE_JPEG => 'jpg', IMAGETYPE_PNG => 'png', IMAGETYPE_GIF => 'gif'];
        if (! $info || ! isset($types[$info[2]]) || $info[0] * $info[1] > 40000000) {
            throw new ApiError(422, 'invalid_image', 'تصویر باید JPEG، PNG یا GIF معتبر باشد.');
        }
        $name = bin2hex(random_bytes(16)).'.'.$types[$info[2]];
        if (! Storage::disk('category_images')->putFileAs('', $file, $name)) {
            throw new RuntimeException('Image storage failed');
        }

        // Keep old images so existing cached readers do not lose their URLs.
        return $name;
    }
}
