<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Search;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class ExtractContentJob implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 390;

    public function __construct(public string $workspace, public string $entry, public string $version, public string $actorType, public string $actorId, public ?string $tenantType, public ?string $tenantId) {}

    public function handle(ExtractTextContent $extract): void
    {
        if (DB::table('fms_content_jobs')->where('version_id', $this->version)->where('state', 'queued')->update(['state' => 'running', 'updated_at' => now()]) !== 1) {
            return;
        }
        try {
            $current = DB::table('fms_entries')->where('public_id', $this->entry)->where('current_version_id', $this->version)->whereNull('trashed_at')->exists();
            $state = $current ? $extract->run(new Principal($this->actorType, $this->actorId), $this->workspace, $this->entry, $this->tenantType === null ? null : new TenantIdentity($this->tenantType, $this->tenantId)) : 'obsolete';
            DB::table('fms_content_jobs')->where('version_id', $this->version)->update(['state' => $state, 'updated_at' => now()]);
        } catch (\Throwable) {
            $this->failed();
        }
    }

    public function failed(?\Throwable $error = null): void
    {
        DB::table('fms_content_jobs')->where('version_id', $this->version)->whereIn('state', ['queued', 'running'])->update(['state' => 'failed', 'updated_at' => now()]);
    }
}
