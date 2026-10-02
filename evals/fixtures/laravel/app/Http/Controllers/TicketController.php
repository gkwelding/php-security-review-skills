<?php

namespace App\Http\Controllers;

use App\Models\Ticket;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class TicketController extends Controller
{
    public function show(Ticket $ticket)
    {
        return view('tickets.show', ['ticket' => $ticket->load('event', 'user')]);
    }

    public function download(Request $request, Ticket $ticket)
    {
        Gate::authorize('view', $ticket);

        $file = $request->query('file', 'ticket.pdf');

        return response()->download(storage_path("app/private/tickets/{$ticket->id}/".$file));
    }

    public function destroy(Request $request, int $id)
    {
        $request->user()->tickets()->findOrFail($id)->delete();

        return redirect('/events')->with('status', 'Ticket cancelled.');
    }
}
