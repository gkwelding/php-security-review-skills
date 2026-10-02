<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;

class PaymentWebhookController extends Controller
{
    public function __invoke(Request $request)
    {
        $payload = $request->json()->all();

        if (($payload['type'] ?? null) === 'payment.succeeded') {
            Ticket::where('reference', $payload['data']['reference'] ?? null)
                ->update(['paid_at' => now()]);
        }

        return response()->noContent();
    }
}
