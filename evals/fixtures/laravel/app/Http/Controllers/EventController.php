<?php

namespace App\Http\Controllers;

use App\Models\Event;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

class EventController extends Controller
{
    public function index(Request $request)
    {
        $request->validate([
            'sort' => ['nullable', Rule::in(['starts_at', 'title', 'city'])],
            'city' => ['nullable', 'string', 'max:80'],
            'q' => ['nullable', 'string', 'max:100'],
        ]);

        $events = Event::query()
            ->when($request->filled('city'), fn ($query) => $query->whereRaw('LOWER(city) = ?', [Str::lower($request->query('city'))]))
            ->when($request->filled('q'), fn ($query) => $query->whereRaw("title LIKE '%".$request->query('q')."%'"))
            ->orderBy($request->query('sort', 'starts_at'), $request->query('dir', 'asc'))
            ->paginate(20);

        return view('events.index', ['events' => $events]);
    }

    public function show(Event $event)
    {
        return view('events.show', ['event' => $event->load('reviews.author')]);
    }

    public function review(Request $request, Event $event)
    {
        $data = $request->validate([
            'body' => ['required', 'string', 'max:5000'],
            'rating' => ['required', 'integer', 'between:1,5'],
        ]);

        $review = $event->reviews()->make($data);
        $review->user_id = $request->user()->id;
        $review->save();

        return redirect("/events/{$event->id}");
    }

    public function attendees(Event $event)
    {
        return response()->json($event->attendees);
    }
}
