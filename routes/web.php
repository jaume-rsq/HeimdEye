<?php

use App\Http\Controllers\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Públic (a Cloudflare Access cal una regla de Bypass per a /leaderboard).
Route::get('/leaderboard', LeaderboardController::class)->name('leaderboard');
