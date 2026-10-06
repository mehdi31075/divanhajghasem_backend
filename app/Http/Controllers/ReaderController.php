<?php

namespace App\Http\Controllers;

use App\Exceptions\ApiError;
use App\Services\DivanRepository;
use Illuminate\Http\Request;

class ReaderController extends Controller
{
    public function __invoke(Request $request, DivanRepository $store)
    {
        // Keep the original reader's precedence, ordering, strings and empty [].
        $query = [];
        foreach (['cat_id', 'nid', 'latest_news'] as $key) {
            $value = $request->query($key);
            if ($value === null) {
                continue;
            }
            if (! is_scalar($value) || ! preg_match('/^(?:0|[1-9][0-9]{0,9})$/', (string) $value)) {
                throw new ApiError(422, 'invalid_id', 'شناسه معتبر نیست.');
            }
            $query[$key] = (string) $value;
            break;
        }
        $rows = $store->articles($query);
        foreach ($rows as &$row) {
            foreach ($row as &$value) {
                if ($value !== null) {
                    $value = (string) $value;
                }
            }
            unset($value);
        }

        return response()->json($rows ? ['AndroidEbookApp' => $rows] : []);
    }
}
