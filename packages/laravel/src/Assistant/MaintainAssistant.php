<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

final class MaintainAssistant extends Command
{
    protected $signature = 'media-studio:assistant:maintain';

    protected $description = 'Recover stale assistant runs and prune a bounded batch of old replay events.';

    public function handle(): int
    {
        if (! Schema::hasTable('fms_ai_runs')) {
            return self::SUCCESS;
        }
        $titles = DB::table('fms_ai_conversations')->where('search_title', '')->whereNull('encrypted_title')->orderBy('id')->limit(100)->get(['id', 'title']);
        foreach ($titles as $title) {
            DB::table('fms_ai_conversations')->where('id', $title->id)->where('title', $title->title)->update(['search_title' => mb_strtolower($title->title, 'UTF-8')]);
        }
        $runs = DB::table('fms_ai_runs')->whereIn('state', ['queued', 'running'])->where('updated_at', '<', now()->subMinutes(10))->orderBy('updated_at')->limit(100)->pluck('id');
        foreach ($runs as $id) {
            RunAssistant::finish($id, 'failed', 'The response timed out. Please try again.');
        }
        // Finished transcripts and operation receipts remain durable. Replay fragments expire.
        $ids = DB::table('fms_ai_events as e')->join('fms_ai_runs as r', 'r.id', '=', 'e.run_id')->where('e.created_at', '<', now()->subDay())->whereNotIn('r.state', ['queued', 'running'])->orderBy('e.id')->limit(1000)->pluck('e.id');
        DB::table('fms_ai_events')->whereIn('id', $ids)->delete();
        if (Schema::hasTable('fms_ai_attachments')) {
            app(AssistantAttachments::class)->prune();
        }
        if (config('media-studio.assistant.retention.enabled', false) === true) {
            $batch = max(1, min(500, (int) config('media-studio.assistant.retention.batch', 50)));
            $cutoff = now()->subDays(max(1, (int) config('media-studio.assistant.retention.inactive_days', 90)));
            $old = DB::table('fms_ai_conversations')->whereNull('deleted_at')->whereNull('active_run')->where('updated_at', '<', $cutoff)->orderBy('updated_at')->limit($batch)->pluck('id');
            DB::table('fms_ai_conversations')->whereIn('id', $old)->whereNull('active_run')->where('updated_at', '<', $cutoff)->update(['deleted_at' => now(), 'revision' => DB::raw('revision + 1')]);
            $purge = DB::table('fms_ai_conversations')->whereNotNull('deleted_at')->whereNull('active_run')->where('deleted_at', '<', now()->subDays(max(1, (int) config('media-studio.assistant.retention.deleted_days', 30))))
                ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_ai_attachments')->whereColumn('conversation_id', 'fms_ai_conversations.id')->whereIn('state', ['ready', 'staging']))->orderBy('deleted_at')->limit($batch)->pluck('id');
            foreach ($purge as $id) {
                DB::transaction(function () use ($id): void {
                    $row = DB::table('fms_ai_conversations')->where('id', $id)->whereNull('active_run')->where('deleted_at', '<', now()->subDays(max(1, (int) config('media-studio.assistant.retention.deleted_days', 30))))->lockForUpdate()->first();
                    if (! $row) {
                        return;
                    }
                    DB::table('fms_ai_attachments')->where('conversation_id', $id)->whereNotIn('state', ['ready', 'staging'])->delete();
                    DB::table('fms_ai_conversations')->where('id', $id)->where('deleted_at', $row->deleted_at)->delete();
                });
            }
        }
        $this->info('Assistant maintenance batch completed.');

        return self::SUCCESS;
    }
}
