<?php

use App\Http\Controllers\Api\V1\Media\MediaController;
use App\Http\Controllers\HealthController;
use Illuminate\Support\Facades\Route;

// Liveness/readiness probe (application, database, cache/redis, queue backlog).
// `/` serves the same probe so the bare API host answers with its status (no session).
Route::get('/', HealthController::class);
Route::get('health', HealthController::class);

// Local media driver only: temporary signed delivery of private files in development.
Route::get('media-local/{media}', [MediaController::class, 'localPrivate'])->name('media.local-private');

// Temporary signed download of a generated wallet statement.
Route::get('statements/download', [MediaController::class, 'statement'])->name('statements.download')->middleware('signed');
