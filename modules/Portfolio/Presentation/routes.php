<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Portfolio\Presentation\Http\Controllers\ProjectApiController;
use Modules\Portfolio\Presentation\Http\Controllers\ProjectPageController;

// D10: session + CSRF ("web"), active organization re-verified per request.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}')
    ->name('api.projects.')
    ->group(function () {
        Route::get('/projects', [ProjectApiController::class, 'index'])->name('index');
        Route::post('/projects', [ProjectApiController::class, 'store'])->middleware(['throttle:projects.create', 'throttle:writes'])->name('store');
        Route::get('/projects/{project}', [ProjectApiController::class, 'show'])->name('show');
        Route::patch('/projects/{project}', [ProjectApiController::class, 'update'])->middleware('throttle:writes')->name('update');
        Route::post('/projects/{project}/members', [ProjectApiController::class, 'addMember'])->middleware('throttle:writes')->name('members.store');
        Route::delete('/projects/{project}/members/{membership}', [ProjectApiController::class, 'removeMember'])->middleware('throttle:writes')->name('members.destroy');
        Route::post('/projects/{project}/teams', [ProjectApiController::class, 'linkTeam'])->middleware('throttle:writes')->name('teams.store');
        Route::delete('/projects/{project}/teams/{team}', [ProjectApiController::class, 'unlinkTeam'])->middleware('throttle:writes')->name('teams.destroy');
    });

// D18: minimal screens until 11-design-system.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/o/{organization}')
    ->name('projects.')
    ->group(function () {
        Route::get('/projects', [ProjectPageController::class, 'index'])->name('index');
        Route::post('/projects', [ProjectPageController::class, 'store'])->middleware(['throttle:projects.create', 'throttle:writes'])->name('store');
        Route::get('/projects/{project}', [ProjectPageController::class, 'show'])->name('show');
    });
