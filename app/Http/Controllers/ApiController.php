<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use Illuminate\Http\Request;

class ApiController extends Controller
{
    public function __invoke(Request $request, DivanApi $api)
    {
        $action = $request->query('action', '');
        if (! is_string($action)) {
            throw new ApiError(422, 'invalid_action', 'درخواست معتبر نیست.');
        }

        if ($request->method() === 'POST' && empty($_POST) && empty($_FILES) && (int) $request->server('CONTENT_LENGTH') > 0) {
            $postMax = ini_get('post_max_size') ?: 'نامشخص';
            throw new ApiError(413, 'payload_too_large', "حجم فایل ارسالی بیشتر از محدودیت سرور (post_max_size: {$postMax}) است. لطفاً محدودیت post_max_size و upload_max_filesize در php.ini یا تنظیمات هاست را افزایش دهید یا فایل را مستقیماً در مسیر storage/app/apk قرار دهید.");
        }

        if ($action === 'app_download' && $request->isMethod('GET')) {
            $id = $request->query('id');
            $version = $request->query('v');
            $download = $api->appDownload(is_string($id) && ctype_digit($id) ? (int) $id : null, is_string($version) ? $version : null);
            if (! $download) {
                return response('فایل اپلیکیشن هنوز روی سرور قرار نگرفته است. لطفاً فایل APK را در بخش نسخه‌های اپلیکیشن بارگذاری یا شناسایی کنید.', 404, [
                    'Content-Type' => 'text/plain; charset=utf-8',
                ]);
            }

            return response()->download($download['path'], $download['filename'], [
                'Content-Type' => 'application/vnd.android.package-archive',
                'Cache-Control' => 'no-cache, must-revalidate',
            ]);
        }

        $private = ['me', 'logout', 'stats', 'posts', 'pages', 'page_update', 'account', 'account_update', 'create', 'update', 'delete', 'category_create', 'category_update', 'category_delete', 'media_list', 'media_upload', 'media_update', 'media_delete', 'support_list', 'support_reply', 'support_reply_update', 'support_reply_delete', 'users', 'app_info', 'app_releases', 'app_upload', 'app_set_latest', 'app_delete', 'app_scan'];
        $requiredMethod = in_array($action, ['me', 'stats', 'posts', 'pages', 'account', 'media_list', 'support_list', 'users', 'app_info', 'app_releases'], true) ? 'GET' : 'POST';
        if (in_array($action, $private, true) && $request->method() === $requiredMethod && ! $request->user('divan')) {
            throw new ApiError(401, 'unauthorized', 'ورود منقضی شده است؛ دوباره وارد شوید.');
        }

        return response()->json($api->handle(
            $request->method(), $action, $request->except(['category_image', 'media_file', 'app_apk']),
            $request->bearerToken(), $request->ip(), null,
            array_filter([
                'category_image' => $request->file('category_image'),
                'media_file' => $request->file('media_file'),
                'app_apk' => $request->file('app_apk'),
            ]),
        ));
    }
}
