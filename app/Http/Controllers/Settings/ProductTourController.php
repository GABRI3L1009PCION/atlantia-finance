<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ProductTourController extends Controller
{
    /** Tours of the application: the firm's workspace and the workspace of a client company. */
    public const TOURS = ['firm', 'company'];

    /**
     * Record that the user finished or skipped a guided tour, so it is not offered again.
     */
    public function update(Request $request, string $tour): RedirectResponse
    {
        $data = $request->validate(['status' => ['required', Rule::in(['completed', 'skipped'])]]);
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['product_tours' => [...($user->product_tours ?? []), $tour => ['status' => $data['status'], 'at' => now()->toIso8601String()]]])->save();

        return back();
    }
}
