<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Work\Presentation\Http\Controllers\WorkApiController;

// W11: session + CSRF ("web"), active organization re-verified per request.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}')
    ->name('api.work.')
    ->group(function () {
        Route::get('/projects/{project}/items', [WorkApiController::class, 'backlog'])->name('backlog');
        Route::post('/projects/{project}/items', [WorkApiController::class, 'store'])->middleware('throttle:writes')->name('store');
        Route::get('/projects/{project}/labels', [WorkApiController::class, 'labels'])->name('labels');
        Route::post('/projects/{project}/labels', [WorkApiController::class, 'storeLabel'])->middleware('throttle:writes')->name('labels.store');
        Route::get('/me/work', [WorkApiController::class, 'myWork'])->name('mine');
        Route::get('/items/by-key/{key}', [WorkApiController::class, 'showByKey'])->name('by-key');
        Route::get('/items/{item}', [WorkApiController::class, 'show'])->name('show');
        Route::patch('/items/{item}', [WorkApiController::class, 'update'])->middleware('throttle:writes')->name('update');
        Route::post('/items/{item}/transitions', [WorkApiController::class, 'transition'])->middleware('throttle:writes')->name('transition');
        Route::get('/items/{item}/activity', [WorkApiController::class, 'activity'])->name('activity');
        Route::post('/items/{item}/assignments', [WorkApiController::class, 'assign'])->middleware('throttle:writes')->name('assignments.store');
        Route::delete('/items/{item}/assignments/{membership}', [WorkApiController::class, 'unassign'])->middleware('throttle:writes')->name('assignments.destroy');
        Route::post('/items/{item}/labels', [WorkApiController::class, 'addLabel'])->middleware('throttle:writes')->name('labels.add');
        Route::delete('/items/{item}/labels/{label}', [WorkApiController::class, 'removeLabel'])->middleware('throttle:writes')->name('labels.remove');
        Route::post('/items/{item}/teams', [WorkApiController::class, 'linkTeam'])->middleware('throttle:writes')->name('teams.store');
        Route::delete('/items/{item}/teams/{team}', [WorkApiController::class, 'unlinkTeam'])->middleware('throttle:writes')->name('teams.destroy');
        Route::post('/items/{item}/dependencies', [WorkApiController::class, 'addDependency'])->middleware('throttle:writes')->name('dependencies.store');
        Route::delete('/items/{item}/dependencies/{blocker}', [WorkApiController::class, 'removeDependency'])->middleware('throttle:writes')->name('dependencies.destroy');
    });
