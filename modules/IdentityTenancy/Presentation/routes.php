<?php

use Illuminate\Support\Facades\Route;
use Modules\IdentityTenancy\Presentation\Http\Controllers\InvitationController;
use Modules\IdentityTenancy\Presentation\Http\Controllers\MemberController;
use Modules\IdentityTenancy\Presentation\Http\Controllers\OrganizationController;
use Modules\IdentityTenancy\Presentation\Http\Middleware\ResolveActiveOrganization;

Route::middleware(['web', 'auth'])->group(function () {
    Route::get('/organizations', [OrganizationController::class, 'index'])->name('organizations.index');
    Route::post('/organizations', [OrganizationController::class, 'store'])->name('organizations.store');
    Route::post('/organizations/switch', [OrganizationController::class, 'switch'])->name('organizations.switch');

    Route::get('/invitations/{organization}/accept', [InvitationController::class, 'open'])->name('invitations.open');
    Route::post('/invitations/{organization}/accept', [InvitationController::class, 'accept'])->name('invitations.accept');

    Route::prefix('/o/{organization}')->middleware(ResolveActiveOrganization::class)->group(function () {
        Route::get('/', [OrganizationController::class, 'show'])->name('organizations.show');
        Route::get('/members', [MemberController::class, 'index'])->name('organizations.members');
        Route::post('/members/invitations', [MemberController::class, 'invite'])->name('organizations.members.invite');
        Route::post('/members/{membership}/revoke', [MemberController::class, 'revoke'])->name('organizations.members.revoke');
    });
});
