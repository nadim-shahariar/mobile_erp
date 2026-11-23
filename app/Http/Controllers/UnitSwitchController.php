<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\BusinessUnit;
use App\Support\UnitContext;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

class UnitSwitchController extends Controller
{
    public function __invoke(Request $request, UnitContext $context): RedirectResponse
    {
        $data = $request->validate([
            'unit_id' => ['required', 'integer', 'exists:business_units,id'],
        ]);

        $unit = BusinessUnit::query()->findOrFail($data['unit_id']);
        $context->set($request->user(), $unit);

        return back();
    }
}
