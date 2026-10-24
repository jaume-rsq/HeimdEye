<?php

use Illuminate\Support\Facades\Route;

// De moment, tota l'app és al panell; el leaderboard públic vindrà més endavant.
Route::redirect('/', '/admin');
