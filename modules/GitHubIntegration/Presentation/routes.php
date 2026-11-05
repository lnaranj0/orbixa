<?php

use Illuminate\Support\Facades\Route;
use Modules\GitHubIntegration\Presentation\Http\Controllers\GitHubIntegrationApiController;
use Modules\GitHubIntegration\Presentation\Http\Controllers\GitHubSetupController;
use Modules\GitHubIntegration\Presentation\Http\Controllers\GitHubWebhookController;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;

// Outside the "web" group on purpose: no session, cookies or CSRF. The
// signature is the only authentication (G3).
Route::post('/webhooks/github', GitHubWebhookController::class)
    ->middleware('throttle:github-webhooks')
    ->name('webhooks.github');

// The App's fixed setup URL: the organization comes from the session-bound
// state. No "auth" middleware: it would keep the URL (and its OAuth code) as
// the intended URL; the controller sends guests to log in instead.
Route::middleware(['web', 'throttle:github-setup'])
    ->get('/integrations/github/callback', [GitHubSetupController::class, 'callback'])
    ->name('github.callback');

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}/integrations/github')
    ->name('api.github.')
    ->group(function () {
        Route::get('/', [GitHubIntegrationApiController::class, 'show'])->middleware('throttle:reads')->name('show');
        Route::post('/setup', [GitHubSetupController::class, 'start'])->middleware('throttle:github-setup')->name('setup');
        Route::put('/repositories/{repository}/selection', [GitHubIntegrationApiController::class, 'select'])->middleware('throttle:writes')->name('repositories.select');
        Route::delete('/repositories/{repository}/selection', [GitHubIntegrationApiController::class, 'deselect'])->middleware('throttle:writes')->name('repositories.deselect');
    });
