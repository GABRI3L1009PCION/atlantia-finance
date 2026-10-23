<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Mail\QuoteRequestMail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;
use Throwable;

/** Public form to ask for a quote for the custom (contact-only) plan; the request is emailed to the team. */
class QuoteRequestController extends Controller
{
    public function create(): Response
    {
        return Inertia::render('quote/request');
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'min:3', 'max:120'],
            'firm' => ['required', 'string', 'min:2', 'max:150'],
            'email' => ['required', 'email:rfc', 'max:150'],
            'phone' => ['nullable', 'string', 'max:30'],
            'offices' => ['nullable', 'string', 'max:40'],
            'message' => ['nullable', 'string', 'max:1500'],
            'website' => ['nullable', 'max:0'],
        ]);
        unset($data['website']);

        try {
            Mail::to((string) config('atlantia-plans.quote_email'))->send(new QuoteRequestMail([
                'name' => $data['name'], 'firm' => $data['firm'], 'email' => $data['email'], 'phone' => $data['phone'] ?? null,
                'offices' => $data['offices'] ?? null, 'message' => $data['message'] ?? null,
            ]));
        } catch (Throwable $exception) {
            report($exception);

            throw ValidationException::withMessages(['email' => 'No pudimos enviar tu solicitud. Escríbenos por WhatsApp al +502 5535 5469.']);
        }

        return back()->with('quote_sent', true);
    }
}
