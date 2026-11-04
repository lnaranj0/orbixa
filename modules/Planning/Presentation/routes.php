<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Planning\Presentation\Http\Controllers\PlanningApiController;

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}')
    ->name('api.planning.')
    ->group(function () {
        Route::get('/projects/{project}/planning', [PlanningApiController::class, 'show'])->name('show');
        Route::put('/projects/{project}/planning', [PlanningApiController::class, 'setMode'])->middleware('throttle:writes')->name('mode');
        Route::post('/projects/{project}/cycles', [PlanningApiController::class, 'create'])->middleware('throttle:writes')->name('cycles.store');
        Route::get('/cycles/{cycle}', [PlanningApiController::class, 'cycle'])->name('cycles.show');
        Route::post('/cycles/{cycle}/start', [PlanningApiController::class, 'start'])->middleware('throttle:writes')->name('cycles.start');
        Route::post('/cycles/{cycle}/close', [PlanningApiController::class, 'close'])->middleware('throttle:writes')->name('cycles.close');
        Route::post('/cycles/{cycle}/items', [PlanningApiController::class, 'addItem'])->middleware('throttle:writes')->name('cycles.items.store');
        Route::delete('/cycles/{cycle}/items/{item}', [PlanningApiController::class, 'removeItem'])->middleware('throttle:writes')->name('cycles.items.destroy');
    });
