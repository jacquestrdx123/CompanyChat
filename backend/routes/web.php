<?php

use Illuminate\Support\Facades\Route;

// API only. Everything lives under /api; see README.md.
Route::get('/', fn () => response()->json(['name' => config('app.name'), 'api' => url('/api')]));
