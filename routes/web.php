<?php

use App\Http\Controllers\BankingCallbackController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

// The single non-home HTTP route in v1: Enable Banking OAuth callback.
// Path is /banking/callback to match the URLs registered in the EB app
// (see .env.example and docs/SPEC.md §9.1).
Route::get('/banking/callback', [BankingCallbackController::class, 'handle']);
