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

        $private = ['me', 'logout', 'stats', 'posts', 'pages', 'page_update', 'account', 'account_update', 'create', 'update', 'delete', 'category_create', 'category_update', 'category_delete'];
        $requiredMethod = in_array($action, ['me', 'stats', 'posts', 'pages', 'account'], true) ? 'GET' : 'POST';
        if (in_array($action, $private, true) && $request->method() === $requiredMethod && ! $request->user('divan')) {
            throw new ApiError(401, 'unauthorized', 'ورود منقضی شده است؛ دوباره وارد شوید.');
        }

        return response()->json($api->handle(
            $request->method(), $action, $request->except('category_image'),
            $request->bearerToken(), $request->ip(), null,
            $request->file('category_image') !== null ? ['category_image' => $request->file('category_image')] : [],
        ));
    }
}
