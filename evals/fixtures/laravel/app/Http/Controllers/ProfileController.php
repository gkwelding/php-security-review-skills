<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProfileController extends Controller
{
    public function update(Request $request)
    {
        $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email', Rule::unique('users')->ignore($request->user())],
            'phone' => ['nullable', 'string', 'max:30'],
        ]);

        $request->user()->update($request->except(['password', 'password_confirmation']));

        return redirect('/profile')->with('status', 'Profile saved.');
    }
}
