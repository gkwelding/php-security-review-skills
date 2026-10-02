<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;

class LocaleController extends Controller
{
    public function switch(Request $request, string $locale)
    {
        abort_unless(in_array($locale, ['en', 'fr', 'de'], true), 404);

        $request->session()->put('locale', $locale);

        return redirect($request->query('back', '/'));
    }
}
