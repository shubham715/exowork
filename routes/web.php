<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| React application
|--------------------------------------------------------------------------
|
| React Router owns all browser-facing routes. Laravel API and webhook
| endpoints are registered separately and are deliberately excluded here.
|
*/
Route::view('/{path?}', 'app')
    ->where('path', '^(?!api(?:/|$)|webhooks(?:/|$)).*');
