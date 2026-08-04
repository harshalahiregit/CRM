<?php

use Illuminate\Support\Facades\Route;

/*
 * The React build is served from this same origin.
 *
 * `npm run build` output is copied into backend/public, so the document root is
 * backend/public for both the API and the app. That keeps everything same-origin:
 * the frontend calls /api/... with no absolute host, so there is no CORS setup and
 * no build-time URL to get wrong when the domain changes.
 *
 * Anything not matched by a real route falls through to index.html and React
 * Router takes over — EXCEPT /api/*, which must keep failing as an API. Without
 * that guard a typo'd endpoint returns index.html with HTTP 200, and the caller
 * tries to parse HTML as JSON instead of seeing a clean 404.
 */
$spa = function () {
    if (request()->is('api/*')) {
        abort(404, 'Not found.');
    }

    $index = public_path('index.html');

    // In dev the React build is not copied into public/ (Vite serves it on its
    // own port), so fall back to the original welcome view rather than 404 —
    // that keeps `GET /` a 200 exactly as it was before the SPA was hosted here.
    if (! file_exists($index)) {
        return response()->view('welcome');
    }

    return response()->file($index);
};

Route::get('/', $spa);
Route::fallback($spa);
