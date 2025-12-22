<?php

use Illuminate\Support\Facades\Route;

// Redirect root to admin panel
Route::get('/', function () {
    return redirect('/admin');
});
