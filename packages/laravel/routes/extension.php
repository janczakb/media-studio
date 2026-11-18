<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Http\AuthorizeStudioOperation;
use UseFlex\MediaStudio\Http\NotesController;
use UseFlex\MediaStudio\Http\StudioController;
use UseFlex\MediaStudio\Integrations\BrowserExtension\AuthorizeExtension;
use UseFlex\MediaStudio\Integrations\BrowserExtension\CaptureController;
use UseFlex\MediaStudio\Integrations\BrowserExtension\DeviceController;

Route::post('devices', [DeviceController::class, 'begin']);
Route::post('devices/claim', [DeviceController::class, 'claim']);
Route::middleware(AuthorizeExtension::class)->group(function (): void {
    Route::get('connection', [CaptureController::class, 'connection']);
    Route::delete('connection', [CaptureController::class, 'revoke']);
    Route::get('workspaces/{workspace}/tags', [CaptureController::class, 'tags'])->whereUlid('workspace');
    Route::get('workspaces/{workspace}/folders', [CaptureController::class, 'folders'])->whereUlid('workspace');
    Route::post('workspaces/{workspace}/captures/{entry}', [CaptureController::class, 'metadata'])->whereUlid(['workspace', 'entry']);
    Route::post('workspaces/{workspace}/uploads', [CaptureController::class, 'upload'])->whereUlid('workspace');
    Route::middleware(AuthorizeStudioOperation::class)->group(function (): void {
        Route::post('workspaces/{workspace}/folders', [StudioController::class, 'createFolder'])->whereUlid('workspace');
        Route::post('workspaces/{workspace}/notes', [NotesController::class, 'store'])->whereUlid('workspace');
        require __DIR__.'/uploads.php';
    });
});
