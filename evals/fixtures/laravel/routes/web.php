<?php

use App\Http\Controllers\BasketController;
use App\Http\Controllers\EventController;
use App\Http\Controllers\LocaleController;
use App\Http\Controllers\PaymentWebhookController;
use App\Http\Controllers\ProfileController;
use App\Http\Controllers\TicketController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/events', [EventController::class, 'index']);
Route::get('/events/{event}', [EventController::class, 'show']);
Route::get('/locale/{locale}', [LocaleController::class, 'switch']);

Route::post('/webhooks/payments', PaymentWebhookController::class);

Route::middleware('auth')->group(function () {
    Route::post('/events/{event}/reviews', [EventController::class, 'review']);
    Route::get('/events/{event}/attendees', [EventController::class, 'attendees'])->can('update', 'event');

    Route::get('/tickets/{ticket}', [TicketController::class, 'show']);
    Route::get('/tickets/{ticket}/pdf', [TicketController::class, 'download']);
    Route::delete('/tickets/{id}', [TicketController::class, 'destroy']);

    Route::patch('/profile', [ProfileController::class, 'update']);

    Route::post('/basket/restore', [BasketController::class, 'restore']);
});
