<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Http\ActivityController;
use UseFlex\MediaStudio\Http\BrowserPushController;

Route::get('workspaces/{workspace}/trash-report', [ActivityController::class, 'trash'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/notifications', [ActivityController::class, 'notifications'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/notifications/read-visible', [ActivityController::class, 'readVisible'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/notifications/read-all', [ActivityController::class, 'readAll'])->whereUlid('workspace');
Route::patch('workspaces/{workspace}/notifications/{notification}', [ActivityController::class, 'read'])->whereUlid(['workspace', 'notification']);

Route::prefix('workspaces/{workspace}/notification-push')->where(['workspace' => '[0-9a-hjkmnp-tv-zA-HJKMNP-TV-Z]{26}'])->group(function (): void {
    Route::get('/', [BrowserPushController::class, 'show']);
    Route::put('preferences', [BrowserPushController::class, 'preferences'])->middleware('throttle:30,1');
    Route::post('devices', [BrowserPushController::class, 'subscribe'])->middleware('throttle:15,1');
    Route::delete('devices/{device}', [BrowserPushController::class, 'unsubscribe'])->where('device', '[a-f0-9]{64}')->middleware('throttle:15,1');
});
