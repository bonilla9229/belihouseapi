<?php

use App\Http\Controllers\Api\GoogleAuthController;
use Illuminate\Support\Facades\Route;

Route::get('/', fn() => view('welcome'));

// Google OAuth — all web routes so Laravel session is available
Route::get('/auth/google/redirect',  [GoogleAuthController::class, 'redirectToGoogle'])->name('auth.google');
Route::get('/auth/google/register',  [GoogleAuthController::class, 'registerWithGoogle'])->name('auth.google.register');
Route::get('/auth/google/callback',  [GoogleAuthController::class, 'handleCallback'])->name('auth.google.callback');

