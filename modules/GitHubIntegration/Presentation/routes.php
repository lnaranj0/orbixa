<?php

use Illuminate\Support\Facades\Route;
use Modules\GitHubIntegration\Presentation\Http\Controllers\GitHubWebhookController;

// Outside the "web" group on purpose: no session, cookies or CSRF. The
// signature is the only authentication (G3).
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:github-webhooks')
    ->name('webhooks.github');
