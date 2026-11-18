<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\ParallelTesting;
use Orchestra\Testbench\TestCase as OrchestraTestCase;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\PrincipalActorResolver;
use UseFlex\MediaStudio\MediaStudioServiceProvider;

abstract class TestCase extends OrchestraTestCase
{
    protected function getPackageProviders($app): array
    {
        return [MediaStudioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.key', 'base64:'.base64_encode(str_repeat('x', 32)));
        $app['config']->set('database.default', 'testing');
        $app['config']->set('database.connections.testing', ['driver' => 'sqlite', 'database' => ':memory:', 'foreign_key_constraints' => true]);
        $app['config']->set('media-studio.principals', ['user' => GenericUser::class]);
        $app['config']->set('media-studio.workspace_creation', true);
        $app['config']->set('media-studio.default_workspace.enabled', false);
        $app['config']->set('media-studio.permissions.actor_resolver', FixtureActorResolver::class);
        $app['config']->set('media-studio.storage.disk', 'studio-test');
        // Explicit legacy fixture profile: these tests exercise WebP/preview integrations,
        // while ImageDefaultsTest verifies the actual shipped AVIF defaults separately.
        $app['config']->set('media-studio.processing.preview.preset', 'preview-v1');
        $app['config']->set('media-studio.processing.renditions', [
            'thumbnail-v1' => ['quality' => 78, 'format' => 'webp'],
            'preview-v1' => ['width' => 1600, 'height' => 1600, 'quality' => 82, 'format' => 'webp'],
        ]);
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        foreach (StudioPermissions::OPERATIONS as $operation) {
            Gate::define('studio.'.$operation, static fn () => true);
        }
        // PHPUnit processes share Testbench's storage path. Isolate fake disks
        // while keeping every test's own Storage::fake() reset semantics.
        $this->app->make(ParallelTesting::class)->resolveTokenUsing(static fn (): string => (string) getmypid());
        Storage::fake('studio-test');
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
    }

    protected function workspace(string $name = 'Brand library'): string
    {
        return $this->postJson('/media-studio/api/v1/workspaces', ['name' => $name])->assertCreated()->json('id');
    }

    protected function url(string $workspace, string $path = 'entries'): string
    {
        return '/media-studio/api/v1/workspaces/'.$workspace.'/'.$path;
    }
}

final class FixtureActorResolver implements PrincipalActorResolver
{
    public function resolve(Principal $principal): Authenticatable
    {
        $class = config('media-studio.principals.'.$principal->type);
        if (is_subclass_of($class, Model::class)) {
            return $class::query()->findOrFail($principal->id);
        }

        return new GenericUser(['id' => $principal->id]);
    }
}
