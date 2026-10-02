<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\HtmlString;

#[Fillable(['title', 'city', 'starts_at', 'tags'])]
class Event extends Model
{
    public function organiser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'organiser_id');
    }

    public function attendees(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'tickets');
    }

    public function reviews(): HasMany
    {
        return $this->hasMany(Review::class);
    }

    public function tagBadges(): HtmlString
    {
        $badges = array_map(
            fn (string $tag) => '<span class="badge">'.e($tag).'</span>',
            $this->tags ?? [],
        );

        return new HtmlString(implode(' ', $badges));
    }

    protected function casts(): array
    {
        return [
            'starts_at' => 'datetime',
            'tags' => 'array',
        ];
    }
}
