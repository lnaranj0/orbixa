<?php

use Illuminate\Support\Facades\Route;

Route::get('/', fn () => auth()->check()
    ? redirect()->route('organizations.index')
    : redirect()->route('login'));
