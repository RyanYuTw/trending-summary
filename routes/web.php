<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

Route::get('/trending-summary/{any?}', function () {
    return view('trending-summary::app');
})->where('any', '.*')->middleware(config('trending-summary.middleware', ['web', 'auth']));
