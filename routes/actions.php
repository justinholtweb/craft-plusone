<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use JustinHoltWeb\PlusOne\Http\Controllers\CallbackController;
use JustinHoltWeb\PlusOne\Http\Controllers\ConnectController;
use JustinHoltWeb\PlusOne\Http\Controllers\LoginController;

/*
 * Action routes: {site}/actions/plusone/… (Craft maps action URLs on every site, including
 * sites that live under a path like /fr/, which plain web routes don't reach). Registered under
 * the site action prefix (public: `craft` + `craft.web`, CSRF on the POSTs) and the CP's.
 */
Route::where(['provider' => '[A-Za-z0-9_\-]+'])->group(function (): void {
    Route::get('login/{provider}', LoginController::class);
    Route::get('callback/{provider}', CallbackController::class);
    Route::post('connect/{provider}', [ConnectController::class, 'connect']);
    Route::post('disconnect/{provider}', [ConnectController::class, 'disconnect']);
});
