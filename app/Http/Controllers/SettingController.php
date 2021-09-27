<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class SettingController extends Controller
{
    public function Steps(Request $request) {
        $shop = $request->shop;

        if ($request->isMethod("GET")) {
            $shop = $request->shop;
        }
    }
}
