<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\ServiceProvider;
use Laravel\Mcp\Facades\Mcp;
use Laravel\Mcp\Server;
use UseFlex\MediaCore\Images\GdImageRenderer;
use UseFlex\MediaCore\Images\ImagickImageRenderer;
use UseFlex\MediaCore\Process\ProcessExecutor;
use UseFlex\MediaCore\Video\VideoRenderer;
use UseFlex\MediaStudio\Activity\DeliverBrowserPush;
use UseFlex\MediaStudio\Api\ApiTokens;
use UseFlex\MediaStudio\Api\AuthorizeApi;
use UseFlex\MediaStudio\Api\PruneApiUsage;
use UseFlex\MediaStudio\ApplicationFiles\IndexApplicationFiles;
use UseFlex\MediaStudio\ApplicationFiles\PruneApplicationThumbnails;
use UseFlex\MediaStudio\ApplicationFiles\WarmApplicationThumbnails;
use UseFlex\MediaStudio\Assistant\AssistantGateway;
use UseFlex\MediaStudio\Assistant\LaravelAssistantGateway;
use UseFlex\MediaStudio\Assistant\MaintainAssistant;
use UseFlex\MediaStudio\Audio\BackfillAudioWaveforms;
use UseFlex\MediaStudio\Http\AuthorizeStudioOperation;
use UseFlex\MediaStudio\Http\StudioRoutes;
use UseFlex\MediaStudio\Integrations\BrowserExtension\DeviceController;
use UseFlex\MediaStudio\Integrations\BrowserExtension\ExtensionTokens;
use UseFlex\MediaStudio\Integrations\Filament\FilamentUiAssets;
use UseFlex\MediaStudio\Integrations\Filament\PermissionEditors;
use UseFlex\MediaStudio\Integrations\Slack\DeliverSlackNotifications;
use UseFlex\MediaStudio\Integrations\Slack\SlackOAuthController;
use UseFlex\MediaStudio\Intelligence\AnalyzeImageCommand;
use UseFlex\MediaStudio\Mcp\AuthorizeMcp;
use UseFlex\MediaStudio\Mcp\McpConnection;
use UseFlex\MediaStudio\Mcp\McpTransferController;
use UseFlex\MediaStudio\Mcp\StudioServer;
use UseFlex\MediaStudio\Members\InstallPermissions;
use UseFlex\MediaStudio\Navigation\MaintainNavigation;
use UseFlex\MediaStudio\Process\SymfonyProcessExecutor;
use UseFlex\MediaStudio\Processing\BackfillRenditions;
use UseFlex\MediaStudio\Processing\MaintainEditedVersions;
use UseFlex\MediaStudio\Processing\PruneOriginals;
use UseFlex\MediaStudio\Processing\PruneRetiredRenditions;
use UseFlex\MediaStudio\Processing\ReconcileRenditions;
use UseFlex\MediaStudio\Search\ExtractSearchContent;
use UseFlex\MediaStudio\Search\IndexSearch;
use UseFlex\MediaStudio\Search\SynchronizeSearch;
use UseFlex\MediaStudio\Sharing\PruneArchives;
use UseFlex\MediaStudio\Sharing\RecoverArchives;
use UseFlex\MediaStudio\Sources\CreateDiskInventory;
use UseFlex\MediaStudio\Sources\ImportDiskManifest;
use UseFlex\MediaStudio\Sources\SynchronizeSources;
use UseFlex\MediaStudio\Standalone\StandaloneProvider;
use UseFlex\MediaStudio\Storage\Encryption\CheckEncryption;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;
use UseFlex\MediaStudio\Storage\Encryption\EncryptedDisks;
use UseFlex\MediaStudio\Storage\Encryption\EncryptionProfiles;
use UseFlex\MediaStudio\Storage\Encryption\MigrateDatabaseEncryption;
use UseFlex\MediaStudio\Storage\Encryption\MigrateFileEncryption;
use UseFlex\MediaStudio\Storage\Encryption\NativeS3Encryption;
use UseFlex\MediaStudio\Trash\MaintainTrash;
use UseFlex\MediaStudio\Trash\PurgeTrash;
use UseFlex\MediaStudio\Uploads\PruneUploadAttempts;
use UseFlex\MediaStudio\Uploads\PruneUploads;
use UseFlex\MediaStudio\Workspaces\MaintainWorkspaces;

final class MediaStudioServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__.'/../config/media-studio.php', 'media-studio');
        if (config('media-studio.auth.enabled') === true) {
            $this->app->register(StandaloneProvider::class);
        }
        $this->app->scoped(DatabasePayloads::class);
        $this->app->bind(AssistantGateway::class, LaravelAssistantGateway::class);
        $this->app->bind(ProcessExecutor::class, SymfonyProcessExecutor::class);
        $this->app->bind(VideoRenderer::class,
            static fn ($app) => new VideoRenderer($app->make(ProcessExecutor::class),
                config('media-studio.processing.video.ffmpeg'), (int) config('media-studio.processing.video.max_output_bytes', 268435456)));
        $this->app->bind(GdImageRenderer::class,
            static fn () => new GdImageRenderer((int) config('media-studio.processing.native.max_working_bytes', 268435456)));
        $this->app->bind(ImagickImageRenderer::class,
            static fn () => new ImagickImageRenderer((int) config('media-studio.processing.native.max_working_bytes', 268435456)));
    }

    public function boot(): void
    {
        app(EncryptionProfiles::class)->configure();
        Storage::extend('fms-s3-native', static fn ($app, array $config) => NativeS3Encryption::make($app, $config));
        Storage::extend('fms-encrypted', static fn ($app, array $config) => EncryptedDisks::make($app, $config));
        $this->loadViewsFrom(__DIR__.'/../resources/views', 'media-studio');
        $this->loadTranslationsFrom(__DIR__.'/../lang', 'media-studio');
        FilamentUiAssets::boot($this->app);

        $this->publishes([__DIR__.'/../config/media-studio.php' => config_path('media-studio.php')], 'media-studio-config');
        $this->publishes([__DIR__.'/../../react/dist' => public_path('vendor/media-studio')], 'media-studio-assets');
        $this->publishesMigrations([__DIR__.'/../database/auth-migrations' => database_path('migrations')], 'media-studio-auth-migrations');
        $this->publishesMigrations([__DIR__.'/../database/assistant-migrations' => database_path('migrations')], 'media-studio-assistant-migrations');
        $this->publishesMigrations([__DIR__.'/../database/extension-migrations' => database_path('migrations')], 'media-studio-extension-migrations');
        $this->publishesMigrations([__DIR__.'/../database/integration-migrations' => database_path('migrations')], 'media-studio-integration-migrations');
        $this->publishesMigrations([__DIR__.'/../database/intelligence-migrations' => database_path('migrations')], 'media-studio-intelligence-migrations');
        $this->publishesMigrations([__DIR__.'/../database/search-migrations' => database_path('migrations')], 'media-studio-search-migrations');
        $this->publishesMigrations([__DIR__.'/../database/application-thumbnail-migrations' => database_path('migrations')], 'media-studio-application-thumbnail-migrations');
        $this->publishesMigrations([__DIR__.'/../database/application-migrations' => database_path('migrations')], 'media-studio-application-migrations');
        $this->publishesMigrations([__DIR__.'/../database/migrations' => database_path('migrations')], 'media-studio-migrations');

        if ($this->app->runningInConsole() && class_exists(PruneUploads::class)) {
            $this->commands([MaintainEditedVersions::class]);
            $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule
                ->command('media-studio:edited-versions:maintain')->everyMinute()->withoutOverlapping(10));
            $this->commands([MaintainNavigation::class, MaintainTrash::class]);
            if (config('media-studio.trash.schedule', true)) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule->command('media-studio:trash:maintain')->everyMinute()->withoutOverlapping());
            }
            $this->callAfterResolving(Schedule::class, static fn (Schedule $s) => $s->command('media-studio:navigation:maintain')->everyMinute()->withoutOverlapping(5));
            $this->commands([MaintainWorkspaces::class, MigrateDatabaseEncryption::class, MigrateFileEncryption::class, CheckEncryption::class]);
            $this->callAfterResolving(Schedule::class, static fn (Schedule $s) => $s->command('media-studio:workspaces:maintain')->everyMinute()->withoutOverlapping(10));
            $this->commands([IndexApplicationFiles::class, PruneApplicationThumbnails::class, WarmApplicationThumbnails::class]);
            if (config('media-studio.application_files.thumbnails.enabled') === true
                && config('media-studio.application_files.thumbnails.schedule_pruning', true) === true) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule
                    ->command('media-studio:application-files:prune-thumbnails')->everyMinute()->withoutOverlapping(10));
            }
            $this->commands([DeliverBrowserPush::class, PruneApiUsage::class, DeliverSlackNotifications::class]);
            if (config('media-studio.integrations.slack.enabled') === true && config('media-studio.integrations.slack.schedule', true)) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule
                    ->command('media-studio:integrations:slack')->everyMinute()->withoutOverlapping(2));
            }
            if (config('media-studio.api.enabled') === true) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule
                    ->command('media-studio:api:prune')->hourly()->withoutOverlapping(10));
            }
            if (config('media-studio.notifications.push.enabled') && config('media-studio.notifications.push.schedule', true)) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule
                    ->command('media-studio:notifications:deliver')->everyTenSeconds()->withoutOverlapping(2));
            }
            if (config('media-studio.assistant.enabled') === true) {
                $this->callAfterResolving(Schedule::class, static fn (Schedule $schedule) => $schedule->command('media-studio:assistant:maintain')->everyMinute()->withoutOverlapping());
            }
            $this->commands([MaintainAssistant::class, AnalyzeImageCommand::class, ExtractSearchContent::class, SynchronizeSearch::class, IndexSearch::class, SynchronizeSources::class, CreateDiskInventory::class, ImportDiskManifest::class, PruneOriginals::class, InstallPermissions::class, PruneUploads::class, PruneUploadAttempts::class, ReconcileRenditions::class, BackfillRenditions::class, BackfillAudioWaveforms::class, PruneRetiredRenditions::class, PurgeTrash::class, PruneArchives::class, RecoverArchives::class]);
        }

        // All providers/configs must be loaded; provider order must not decide
        // whether an optional role editor sees Studio's complete catalogue.
        $this->app->booted(static fn () => app(PermissionEditors::class)->register());

        if (config('media-studio.mcp.enabled') === true && class_exists(Server::class) && ! $this->app->routesAreCached()) {
            $path = '/'.trim((string) config('media-studio.mcp.path', 'media-studio/mcp'), '/');
            $middleware = McpConnection::managed()
                ? (array) config('media-studio.mcp.connection_middleware', [])
                : (array) config('media-studio.mcp.middleware', ['auth:sanctum']);
            $middleware[] = AuthorizeMcp::class;
            $middleware[] = 'throttle:'.max(1, min(600, (int) config('media-studio.mcp.requests_per_minute', 60))).',1';
            Mcp::web($path, StudioServer::class)->name('media-studio.mcp')->middleware($middleware);
            Route::prefix($path)->middleware($middleware)->group(function (): void {
                Route::get('workspaces/{workspace}/files/{entry}/versions/{version}', [McpTransferController::class, 'download'])
                    ->whereUlid(['workspace', 'entry', 'version'])->name('media-studio.mcp.download');
                Route::post('workspaces/{workspace}/files', [McpTransferController::class, 'store'])
                    ->whereUlid('workspace')->name('media-studio.mcp.upload');
                Route::post('workspaces/{workspace}/image-bundles', [McpTransferController::class, 'importImages'])
                    ->whereUlid('workspace')->name('media-studio.mcp.import');
                Route::put('workspaces/{workspace}/uploads/{session}/parts/{part}', [McpTransferController::class, 'part'])
                    ->whereUlid(['workspace', 'session'])->where('part', '0|[1-9][0-9]{0,3}')->name('media-studio.mcp.upload.part');
            });
        }

        if (! $this->app->routesAreCached()) {
            Route::get('.well-known/flex-media-studio.json', [DeviceController::class, 'discovery']);
            Route::prefix(ExtensionTokens::prefix())
                ->name('media-studio.extension.')->group(__DIR__.'/../routes/extension.php');
            Route::get(trim((string) config('media-studio.routes.prefix'), '/').'/integrations/slack/callback', [SlackOAuthController::class, 'callback'])
                ->name('media-studio.slack.callback')->middleware((array) config('media-studio.integrations.slack.oauth.middleware', ['web', 'auth']))->middleware('throttle:30,1');
            Route::prefix(ApiTokens::prefix())->name('media-studio.rest.')
                ->where(['workspace' => '[0-9A-HJKMNP-TV-Z]{26}', 'entry' => '[0-9A-HJKMNP-TV-Z]{26}', 'version' => '[0-9A-HJKMNP-TV-Z]{26}'])
                ->middleware([AuthorizeApi::class, AuthorizeStudioOperation::class])
                ->group(__DIR__.'/../routes/rest.php');
        }

        if (config('media-studio.routes.enabled') && ! $this->app->routesAreCached()) {
            StudioRoutes::register((array) config('media-studio.routes.middleware'));
        }
    }
}
