<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Console\Command;

final class MigrateFileEncryption extends Command
{
    protected $signature = 'media-studio:encryption:files {--workspace= : Workspace ULID for a new operation} {--target= : Dedicated encrypted Laravel disk} {--resume= : Existing operation ULID} {--batch=1 : Maximum objects per invocation}';

    protected $description = 'Copy, verify and migrate owned workspace objects; retain original recovery copies';

    public function handle(FileEncryptionMigration $migration): int
    {
        if (! $this->option('resume') && (! $this->option('workspace') || ! $this->option('target'))) {
            $this->error('Provide --workspace and --target, or --resume.');

            return self::INVALID;
        }
        $id = $this->option('resume') ?: $migration->start((string) $this->option('workspace'), (string) $this->option('target'));
        $this->line('Operation: '.$id);
        $operation = $migration->step($id, (int) $this->option('batch'));
        $this->line('State: '.$operation->state.'; migrated pointers: '.$operation->processed);
        $this->line('Original recovery objects remain until separately pruned; this is not full plaintext retirement.');

        return self::SUCCESS;
    }
}
