<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Uploads\UploadController;

Route::get('workspaces/{workspace}/upload-policy', [UploadController::class, 'policy'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/upload-sessions', [UploadController::class, 'create'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/upload-sessions/{session}', [UploadController::class, 'status'])->whereUlid(['workspace', 'session']);
Route::put('workspaces/{workspace}/upload-sessions/{session}/parts/{part}', [UploadController::class, 'part'])->whereUlid(['workspace', 'session'])->whereNumber('part');
Route::post('workspaces/{workspace}/upload-sessions/{session}/parts/{part}/sign', [UploadController::class, 'signPart'])->whereUlid(['workspace', 'session'])->whereNumber('part');
Route::post('workspaces/{workspace}/upload-sessions/{session}/parts/{part}/receipt', [UploadController::class, 'recordPart'])->whereUlid(['workspace', 'session'])->whereNumber('part');
Route::post('workspaces/{workspace}/upload-sessions/{session}/complete', [UploadController::class, 'complete'])->whereUlid(['workspace', 'session']);
Route::delete('workspaces/{workspace}/upload-sessions/{session}', [UploadController::class, 'cancel'])->whereUlid(['workspace', 'session']);
