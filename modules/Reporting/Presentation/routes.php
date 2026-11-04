<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Contracts\ActiveOrganization;
use Modules\Reporting\Presentation\Http\Controllers\FlowReportController;

Route::middleware(['web', 'auth', ActiveOrganization::MIDDLEWARE, 'throttle:reads'])
    ->get('/api/v1/organizations/{organization}/reports/flow', FlowReportController::class)
    ->name('api.reports.flow');
