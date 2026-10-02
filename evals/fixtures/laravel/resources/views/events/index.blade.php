<!DOCTYPE html>
<html lang="en">
<head><title>Events</title></head>
<body>
    <h1>Events</h1>
    <ul>
        @foreach ($events as $event)
            <li><a href="/events/{{ $event->id }}">{{ $event->title }}</a> ({{ $event->city }})</li>
        @endforeach
    </ul>
    {{ $events->links() }}
</body>
</html>
