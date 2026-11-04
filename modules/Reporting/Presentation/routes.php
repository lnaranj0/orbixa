<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Reporting\Presentation\Http\Controllers\FlowReportController;
use Modules\Reporting\Presentation\Http\Controllers\FlowReportPageController;

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE, 'throttle:reads'])
    ->get('/api/v1/organizations/{organization}/reports/flow', FlowReportController::class)
    ->name('api.reports.flow');

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE, 'throttle:reads'])
    ->get('/o/{organization}/reports/flow', FlowReportPageController::class)
    ->name('reports.flow');
