<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Console\Command;

final class MigrateDatabaseEncryption extends Command
{
    protected $signature = 'media-studio:encryption:database {--resume= : Existing operation ULID} {--batch=25 : Maximum rows this invocation} {--seconds=5 : Maximum processing window}';

    protected $description = 'Encrypt/rekey protected Studio database payloads using a durable resumable checkpoint';

    public function handle(DatabaseEncryptionMigration $migration): int
    {
        $id = $this->option('resume') ?: $migration->start();
        $this->line('Operation: '.$id);
        $operation = $migration->step($id, (int) $this->option('batch'), (int) $this->option('seconds'));
        $this->line('State: '.$operation->state.'; checked rows: '.$operation->processed);

        return $operation->state === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
