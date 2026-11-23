<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class LocaleSwitchController extends Controller
{
    public function __invoke(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'locale' => ['required', 'string', 'in:'.implode(',', array_keys((array) config('erp.locales')))],
        ]);

        $request->session()->put('locale', $data['locale']);

        $user = $request->user();

        if ($user !== null) {
            $user->forceFill(['locale' => $data['locale']])->save();
        }

        return back();
    }
}
