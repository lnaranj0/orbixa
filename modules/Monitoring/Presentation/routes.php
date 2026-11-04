<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Monitoring\Presentation\Http\Controllers\MonitoringApiController;
use Modules\Monitoring\Presentation\Http\Controllers\MonitoringPageController;

// M10: monitoring reads are GET only (other methods: 405). Grant
// administration needs monitoring.grants.manage. No export route in 04.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}/monitoring')
    ->name('api.monitoring.')
    ->group(function () {
        Route::get('/overview', [MonitoringApiController::class, 'overview'])->name('overview');
        Route::get('/items', [MonitoringApiController::class, 'items'])->name('items');
        Route::get('/items/{item}', [MonitoringApiController::class, 'item'])->name('item');
        Route::get('/users/{member}', [MonitoringApiController::class, 'member'])->name('member');
        Route::get('/teams/{team}', [MonitoringApiController::class, 'team'])->name('team');
        Route::get('/grants', [MonitoringApiController::class, 'grants'])->name('grants');
        Route::post('/grants', [MonitoringApiController::class, 'issue'])->middleware('throttle:writes')->name('grants.store');
        Route::patch('/grants/{grant}', [MonitoringApiController::class, 'update'])->middleware('throttle:writes')->name('grants.update');
        Route::post('/grants/{grant}/revoke', [MonitoringApiController::class, 'revoke'])->middleware('throttle:writes')->name('grants.revoke');
    });

// M10: minimal screens until 11-design-system.
Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/o/{organization}/monitoring')
    ->name('monitoring.')
    ->group(function () {
        Route::get('/', [MonitoringPageController::class, 'panel'])->name('panel');
        Route::get('/items/{item}', [MonitoringPageController::class, 'item'])->name('item');
        Route::get('/grants', [MonitoringPageController::class, 'grants'])->name('grants');
        Route::post('/grants/{grant}/revoke', [MonitoringPageController::class, 'revoke'])->middleware('throttle:writes')->name('grants.revoke');
    });
