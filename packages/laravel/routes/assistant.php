<?php

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Assistant\AssistantActionsController;
use UseFlex\MediaStudio\Assistant\AssistantController;
use UseFlex\MediaStudio\Assistant\AssistantProfileController;

Route::prefix('workspaces/{workspace}/assistant')->whereUlid('workspace')->group(function (): void {
    Route::post('actions/{action}/undo', [AssistantActionsController::class, 'undo'])->whereUlid('action')->middleware('throttle:30,1');
    Route::post('attachments', [AssistantController::class, 'upload'])->middleware('throttle:20,1');
    Route::delete('attachments/{attachment}', [AssistantController::class, 'discardAttachment'])->whereUlid('attachment');
    Route::get('profile', [AssistantProfileController::class, 'show']);
    Route::put('profile', [AssistantProfileController::class, 'update'])->middleware('throttle:30,1');
    Route::get('conversations', [AssistantController::class, 'index']);
    Route::post('conversations', [AssistantController::class, 'store'])->middleware('throttle:20,1');
    Route::get('conversations/{conversation}', [AssistantController::class, 'show'])->whereUlid('conversation');
    Route::patch('conversations/{conversation}', [AssistantController::class, 'rename'])->whereUlid('conversation');
    Route::delete('conversations/{conversation}', [AssistantController::class, 'remove'])->whereUlid('conversation');
    Route::post('conversations/{conversation}/restore', [AssistantController::class, 'restoreConversation'])->whereUlid('conversation');
    Route::post('conversations/{conversation}/messages', [AssistantController::class, 'send'])->whereUlid('conversation')->middleware('throttle:20,1');
    Route::post('conversations/{conversation}/cancel', [AssistantController::class, 'cancel'])->whereUlid('conversation');
    Route::get('conversations/{conversation}/runs/{run}/events', [AssistantController::class, 'events'])->whereUlid(['conversation', 'run'])->middleware('throttle:120,1');
});
