<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class BasketController extends Controller
{
    public function restore(Request $request)
    {
        $items = unserialize(hex2bin((string) $request->input('saved_basket')));

        $request->session()->put('basket', is_array($items) ? $items : []);

        return redirect('/basket');
    }
}
