<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Portfolio\Presentation\Http\Controllers\ProjectApiController;

// D10: session + CSRF ("web"), active organization re-verified per request.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}')
    ->name('api.projects.')
    ->group(function () {
        Route::get('/projects', [ProjectApiController::class, 'index'])->name('index');
        Route::post('/projects', [ProjectApiController::class, 'store'])->middleware('throttle:projects.create')->name('store');
        Route::get('/projects/{project}', [ProjectApiController::class, 'show'])->name('show');
        Route::patch('/projects/{project}', [ProjectApiController::class, 'update'])->name('update');
        Route::post('/projects/{project}/members', [ProjectApiController::class, 'addMember'])->name('members.store');
        Route::delete('/projects/{project}/members/{membership}', [ProjectApiController::class, 'removeMember'])->name('members.destroy');
        Route::post('/projects/{project}/teams', [ProjectApiController::class, 'linkTeam'])->name('teams.store');
        Route::delete('/projects/{project}/teams/{team}', [ProjectApiController::class, 'unlinkTeam'])->name('teams.destroy');
    });
