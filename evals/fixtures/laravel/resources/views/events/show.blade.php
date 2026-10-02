<!DOCTYPE html>
<html lang="en">
<head><title>{{ $event->title }}</title></head>
<body>
    <h1>{{ $event->title }}</h1>
    <p>{{ $event->city }}, {{ $event->starts_at->format('j M Y H:i') }}</p>
    <p>{!! $event->tagBadges() !!}</p>

    <h2>Reviews</h2>
    @foreach ($event->reviews as $review)
        <article>
            <h3>{{ $review->author->name }} ({{ $review->rating }}/5)</h3>
            <div>{!! Str::markdown($review->body) !!}</div>
        </article>
    @endforeach

    @auth
        <form method="POST" action="/events/{{ $event->id }}/reviews">
            @csrf
            <textarea name="body"></textarea>
            <input type="number" name="rating" min="1" max="5">
            <button>Post review</button>
        </form>
    @endauth
</body>
</html>
