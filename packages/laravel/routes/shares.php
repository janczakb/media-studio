<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Http\SharingController;

Route::get('workspaces/{workspace}/shares', [SharingController::class, 'index'])->whereUlid('workspace');
Route::post('workspaces/{workspace}/shares', [SharingController::class, 'store'])->whereUlid('workspace');
Route::get('workspaces/{workspace}/shares/{share}/entries', [SharingController::class, 'roots'])->whereUlid(['workspace', 'share']);
Route::patch('workspaces/{workspace}/shares/{share}', [SharingController::class, 'update'])->whereUlid(['workspace', 'share']);
Route::post('workspaces/{workspace}/shares/{share}/rotate', [SharingController::class, 'rotate'])->whereUlid(['workspace', 'share']);

Route::get('workspaces/{workspace}/shares/{share}/link', [SharingController::class, 'link'])->whereUlid(['workspace', 'share']);
Route::get('workspaces/{workspace}/shares/{share}/analytics', [SharingController::class, 'analytics'])->whereUlid(['workspace', 'share']);
Route::delete('workspaces/{workspace}/shares/{share}', [SharingController::class, 'destroy'])->whereUlid(['workspace', 'share']);

Route::post('workspaces/{workspace}/shares/invitations', [SharingController::class, 'invite'])->whereUlid('workspace');
