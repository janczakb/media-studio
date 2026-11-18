<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Support\Facades\Route;
use UseFlex\MediaStudio\Api\ApiAccessController;
use UseFlex\MediaStudio\Delivery\EdgeGrant;
use UseFlex\MediaStudio\Integrations\BrowserExtension\DeviceController;
use UseFlex\MediaStudio\Sharing\SharingRoutes;

final class StudioRoutes
{
    /** @param list<class-string|string> $middleware */
    public static function register(array $middleware = []): void
    {
        if (config('media-studio.auth.enabled') === true) {
            $middleware = [...$middleware, 'auth:studio', 'verified'];
        }
        Route::prefix((string) config('media-studio.routes.prefix'))
            ->name('media-studio.')
            ->middleware([...$middleware, AuthorizeStudioOperation::class])
            ->group(function (): void {
                require __DIR__.'/../../routes/api.php';
                Route::get('workspaces/{workspace}/integrations/browser-extension/devices', [DeviceController::class, 'devices'])->whereUlid('workspace');
                Route::delete('workspaces/{workspace}/integrations/browser-extension/devices/{device}', [DeviceController::class, 'revoke'])->whereUlid(['workspace', 'device']);
                Route::post('workspaces/{workspace}/integrations/browser-extension/approve', [DeviceController::class, 'approve'])->whereUlid('workspace')->middleware('throttle:10,1');
                Route::get('workspaces/{workspace}/api-access', [ApiAccessController::class, 'show'])->whereUlid('workspace');
                Route::post('workspaces/{workspace}/api-access/tokens', [ApiAccessController::class, 'store'])->whereUlid('workspace')->middleware('throttle:10,1');
                Route::delete('workspaces/{workspace}/api-access/tokens/{token}', [ApiAccessController::class, 'revoke'])->whereUlid(['workspace', 'token']);
                require __DIR__.'/../../routes/uploads.php';
                require __DIR__.'/../../routes/activity.php';
                require __DIR__.'/../../routes/assistant.php';
            });
        Route::prefix((string) config('media-studio.routes.prefix'))->middleware($middleware)->group(function (): void {
            Route::get('invitations/{token}', [MemberInvitationsController::class, 'show'])->where('token', '[a-f0-9]{64}');
            Route::post('invitations/{token}', [MemberInvitationsController::class, 'accept'])->where('token', '[a-f0-9]{64}');
        });
        Route::post(trim((string) config('media-studio.routes.prefix'), '/').'/delivery/check', [EdgeGrant::class, 'check'])->name('media-studio.delivery.check')->middleware('throttle:'.max(1, min(60000, (int) config('media-studio.delivery.edge_authorization_limit', 6000))).',1');
        Route::get(trim((string) config('media-studio.routes.prefix'), '/').'/push/worker.js', [BrowserPushController::class, 'worker'])
            ->name('media-studio.push.worker');
        SharingRoutes::register([...$middleware, AuthorizeStudioOperation::class]);
    }
}
