<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Workflow\Presentation\Http\Controllers\WorkflowApiController;

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE])
    ->prefix('/api/v1/organizations/{organization}/projects/{project}/workflow')
    ->name('api.workflow.')
    ->group(function () {
        Route::get('/', [WorkflowApiController::class, 'show'])->name('show');
        Route::post('/statuses', [WorkflowApiController::class, 'addStatus'])->middleware('throttle:writes')->name('statuses.store');
        Route::patch('/statuses/{status}', [WorkflowApiController::class, 'updateStatus'])->middleware('throttle:writes')->name('statuses.update');
        Route::post('/statuses/{status}/archive', [WorkflowApiController::class, 'archiveStatus'])->middleware('throttle:writes')->name('statuses.archive');
        Route::post('/transitions', [WorkflowApiController::class, 'addTransition'])->middleware('throttle:writes')->name('transitions.store');
        Route::patch('/transitions/{transition}', [WorkflowApiController::class, 'updateTransition'])->middleware('throttle:writes')->name('transitions.update');
        Route::post('/transitions/{transition}/disable', [WorkflowApiController::class, 'disableTransition'])->middleware('throttle:writes')->name('transitions.disable');
    });
