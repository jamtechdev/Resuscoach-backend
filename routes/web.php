<?php

use Illuminate\Support\Facades\Route;

// Redirect root to admin panel
Route::get('/', function () {
    return redirect('/admin');
});

/*
| When the web server cannot serve public/storage (symlink, nginx rules, or 403 on a subdomain),
| Laravel can stream these files. If the file exists on disk and Apache/nginx serves it first,
| this route is not hit.
*/
Route::get('/storage/{path}', function (string $path) {
    if (str_contains($path, '..')) {
        abort(404);
    }
    $path = str_replace('\\', '/', $path);
    $root = realpath(storage_path('app/public'));
    if ($root === false) {
        abort(404);
    }
    $full = realpath($root . DIRECTORY_SEPARATOR . $path);
    if ($full === false || ! str_starts_with($full, $root)) {
        abort(404);
    }

    return response()->file($full);
})->where('path', '.*');
