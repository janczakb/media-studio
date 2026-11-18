<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Http\GuestUploadController;
use UseFlex\MediaStudio\Http\ShareCopyController;
use UseFlex\MediaStudio\Http\SharedArchiveController;
use UseFlex\MediaStudio\Http\SharingController;

final class SharingRoutes
{
    /** Public capability routes intentionally do not inherit the panel's auth middleware. */
    public static function register(array $middleware = []): void
    {
        $prefix = trim((string) config('media-studio.routes.prefix'), '/');
        Route::prefix($prefix)->name('media-studio.')->middleware($middleware)->group(function (): void {
            require __DIR__.'/../../routes/shares.php';
        });
        Route::prefix($prefix.'/s')->name('media-studio.shared.')->middleware([PrivateShareResponse::class, 'web'])->group(function (): void {
            Route::get('{token}', [SharingController::class, 'show'])->name('show')->where('token', '[a-f0-9]{64}');
            Route::post('{token}/engagement', [SharingController::class, 'engagement'])->name('engagement')->where('token', '[a-f0-9]{64}')->middleware('throttle:60,1');
            Route::post('{token}/entries/{entry}/copy', ShareCopyController::class)->name('copy')->where('token', '[a-f0-9]{64}')->whereUlid('entry')->middleware('throttle:10,1');
            Route::post('{token}/comments', [SharingController::class, 'comment'])->name('comment')->where('token', '[a-f0-9]{64}')->middleware('throttle:10,1');
            Route::post('{token}/entries/{entry}/rename', [SharingController::class, 'rename'])->name('rename')->where('token', '[a-f0-9]{64}')->whereUlid('entry')->middleware('throttle:20,1');
            Route::post('{token}/unlock', [SharingController::class, 'unlock'])->name('unlock')->where('token', '[a-f0-9]{64}');
            Route::post('{token}/uploads', [GuestUploadController::class, 'store'])->name('upload')->where('token', '[a-f0-9]{64}');
            Route::get('{token}/entries', [SharingController::class, 'publicEntries'])->name('entries')->where('token', '[a-f0-9]{64}');
            Route::get('{token}/download', [SharingController::class, 'archive'])->name('archive')->where('token', '[a-f0-9]{64}');
            Route::post('{token}/archive-jobs', [SharedArchiveController::class, 'create'])->name('archive-create')->where('token', '[a-f0-9]{64}');
            Route::get('{token}/archive-jobs/{job}', [SharedArchiveController::class, 'status'])->name('archive-status')->where('token', '[a-f0-9]{64}')->whereUlid('job');
            Route::get('{token}/archive-jobs/{job}/result', [SharedArchiveController::class, 'result'])->name('archive-result')->where('token', '[a-f0-9]{64}')->whereUlid('job');
            Route::get('{token}/entries/{entry}/{operation}', [SharingController::class, 'content'])
                ->name('content')->where('token', '[a-f0-9]{64}')->whereUlid('entry')->where('operation', 'preview|thumbnail|download');
        });
    }
}
