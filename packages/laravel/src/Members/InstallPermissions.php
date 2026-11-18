<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Members;

use Illuminate\Console\Command;
use Spatie\Permission\Contracts\Permission;
use Spatie\Permission\PermissionRegistrar;
use UseFlex\MediaStudio\Access\PermissionDefinitions;

final class InstallPermissions extends Command
{
    protected $signature = 'media-studio:permissions {--guard=web : Authentication guard used by your users}';

    protected $description = 'Create Studio permission definitions using the configured Spatie permission model.';

    public function handle(): int
    {
        $guard = $this->option('guard');
        $class = app(PermissionRegistrar::class)->getPermissionClass();
        if (! is_string($class) || ! is_subclass_of($class, Permission::class)
            || ! is_string($guard) || ! array_key_exists($guard, (array) config('auth.guards', []))) {
            $this->error('Configure Spatie Permission and its permission model first.');

            return self::FAILURE;
        }
        $definitions = app(PermissionDefinitions::class)->all();
        (new $class)->getConnection()->transaction(static function () use ($definitions, $class, $guard): void {
            foreach (array_keys($definitions) as $name) {
                $class::findOrCreate($name, $guard);
            }
        });
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->info('Studio permission definitions are ready. Assign them to your application roles.');

        return self::SUCCESS;
    }
}
