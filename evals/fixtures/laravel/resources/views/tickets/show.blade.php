<!DOCTYPE html>
<html lang="en">
<head><title>Ticket {{ $ticket->reference }}</title></head>
<body>
    <h1>{{ $ticket->event->title }}</h1>
    <p>Ticket {{ $ticket->reference }}</p>
    <p>Holder: {{ $ticket->user->name }}, {{ $ticket->user->email }}</p>
    <p>{{ $ticket->paid_at ? 'Paid' : 'Awaiting payment' }}</p>
    <a href="/tickets/{{ $ticket->id }}/pdf">Download PDF</a>
</body>
</html>
