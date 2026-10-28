<?php

use App\Http\Controllers\LeaderboardController;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/admin');

// Públic (a Cloudflare Access cal una regla de Bypass per a /leaderboard).
Route::get('/leaderboard', LeaderboardController::class)->name('leaderboard');

// Pantalles antigues que ara són pestanyes de l'Inventari.
Route::redirect('/admin/variables', '/admin/inventari?pestanya=variables&modul=0');
Route::redirect('/admin/secrets', '/admin/inventari?pestanya=secrets&modul=0');
Route::redirect('/admin/equips', '/admin/inventari?pestanya=equips&modul=0');
Route::redirect('/admin/moduls', '/admin/inventari?pestanya=equips');
Route::get('/admin/moduls/{modul}/{rest?}', fn (int $modul) => redirect("/admin/inventari?pestanya=equips&modul={$modul}"))->whereNumber('modul');
