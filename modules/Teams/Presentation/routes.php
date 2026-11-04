<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Teams\Presentation\Http\Controllers\TeamApiController;
use Modules\Teams\Presentation\Http\Controllers\TeamPageController;

// D10: session + CSRF ("web"), active organization re-verified per request.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}')
    ->name('api.teams.')
    ->group(function () {
        Route::get('/teams', [TeamApiController::class, 'index'])->name('index');
        Route::post('/teams', [TeamApiController::class, 'store'])->middleware('throttle:writes')->name('store');
        Route::get('/teams/{team}', [TeamApiController::class, 'show'])->name('show');
        Route::post('/teams/{team}/move', [TeamApiController::class, 'move'])->middleware('throttle:writes')->name('move');
        Route::post('/teams/{team}/archive', [TeamApiController::class, 'archive'])->middleware('throttle:writes')->name('archive');
        Route::post('/teams/{team}/members', [TeamApiController::class, 'addMember'])->middleware('throttle:writes')->name('members.store');
        Route::delete('/teams/{team}/members/{membership}', [TeamApiController::class, 'removeMember'])->middleware('throttle:writes')->name('members.destroy');
    });

// D18: minimal screens until 11-design-system.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/o/{organization}')
    ->name('teams.')
    ->group(function () {
        Route::get('/teams', [TeamPageController::class, 'index'])->name('index');
        Route::post('/teams', [TeamPageController::class, 'store'])->middleware('throttle:writes')->name('store');
    });
