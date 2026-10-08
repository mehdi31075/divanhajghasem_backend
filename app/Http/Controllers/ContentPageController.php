<?php

namespace App\Http\Controllers;

use App\Services\DivanRepository;

class ContentPageController extends Controller
{
    public function __invoke(DivanRepository $store)
    {
        return response()->json(['pages' => $store->pages()]);
    }
}
