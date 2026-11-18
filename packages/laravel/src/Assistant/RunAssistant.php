<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class RunAssistant implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public bool $failOnTimeout = true;

    public function __construct(public string $run, public Principal $principal, public string $workspace, public ?TenantIdentity $tenant) {}

    public function handle(AssistantGateway $gateway): void
    {
        // A redelivered job must never invoke a provider a second time.
        if (! DB::table('fms_ai_runs')->where('id', $this->run)->where('state', 'queued')->update(['state' => 'running', 'updated_at' => now()])) {
            return;
        }
        try {
            abort_unless(app(AssistantConfiguration::class)->available(), 403);
            $scope = $this->authorize();
            $run = DB::table('fms_ai_runs')->where('id', $this->run)->first();
            $run = app(DatabasePayloads::class)->reveal('fms_ai_runs', $run->id, $run);
            abort_unless(DB::table('fms_ai_conversations')->where('id', $run->conversation_id)->where('workspace_id', $scope->id)->where('principal_key', $this->principal->key())->whereNull('deleted_at')->exists(), 404);
            $context = ['workspace' => $scope->name, 'entries' => []];
            foreach (json_decode($run->context, true, flags: JSON_THROW_ON_ERROR) as $id) {
                $entry = app(Entries::class)->resolve($scope->id, $id);
                abort_if($entry->trashed_at !== null, 404);
                abort_if(app(SourcePolicy::class)->visible($scope, $this->principal, [$entry]) === [], 404);
                app(SourcePolicy::class)->readable($scope, $this->principal, $entry);
                $context['entries'][] = ['id' => $entry->public_id, 'name' => $entry->name, 'kind' => $entry->kind, 'revision' => (int) $entry->revision, 'mimeType' => $entry->mime_type, 'size' => $entry->size_bytes];
            }
            $history = DB::table('fms_ai_messages')->where('conversation_id', $run->conversation_id)->orderByDesc('id')->limit(59)->get(['id', 'role', 'content'])->map(fn ($m) => app(DatabasePayloads::class)->reveal('fms_ai_messages', $m->id, $m))->reverse()->values();
            $prompt = $history->pop()->content;
            $messages = [];
            $bytes = strlen($prompt);
            foreach ($history->reverse() as $message) {
                if ($bytes + strlen($message->content) > 65536) {
                    break;
                }
                array_unshift($messages, ['role' => $message->role, 'content' => $message->content]);
                $bytes += strlen($message->content);
            }
            $context['conversationContextIsWindowed'] = true;
            $context['contextInstruction'] = 'Recent conversation context is bounded. Older messages remain in saved history but may be omitted here. Ask for missing historical details rather than inventing them.';
            $context['_attachments'] = app(AssistantAttachments::class)->forProvider($scope, $this->principal, $run->id);
            $context['_execution'] = ['run' => $this->run, 'principal' => $this->principal, 'workspace' => $this->workspace, 'tenant' => $this->tenant];
            $text = '';
            $buffer = '';
            $usage = [];
            $lastFlush = microtime(true);
            foreach ($gateway->stream(['provider' => $run->provider, 'model' => $run->model], $messages, $prompt, $context) as $event) {
                if (($event['type'] ?? '') === 'usage') {
                    $usage = $event['usage'];
                }
                if (($event['type'] ?? '') === 'delta') {
                    $text .= $event['text'];
                    $buffer .= $event['text'];
                    if (strlen($text) > 32768) {
                        throw new \RuntimeException('Assistant output exceeded its byte limit.');
                    }
                }
                if (microtime(true) - $lastFlush >= .2 || strlen($buffer) >= 2048) {
                    $this->authorize();
                    if (DB::table('fms_ai_runs')->where('id', $this->run)->value('state') !== 'running') {
                        return;
                    }
                    if ($buffer !== '') {
                        self::event($this->run, 'delta', ['text' => $buffer]);
                    }
                    $buffer = '';
                    $lastFlush = microtime(true);
                    DB::table('fms_ai_runs')->where('id', $this->run)->where('state', 'running')->update(app(DatabasePayloads::class)->protect('fms_ai_runs', $this->run, ['updated_at' => now(), 'partial_text' => $text]));
                }
            }
            $this->authorize();
            DB::transaction(function () use ($run, $text, $buffer, $usage): void {
                $row = DB::table('fms_ai_conversations')->where('id', $run->conversation_id)->lockForUpdate()->first();
                if ($row->active_run !== $this->run) {
                    return;
                }
                if ($buffer !== '') {
                    self::event($this->run, 'delta', ['text' => $buffer]);
                }
                $messageId = (string) Str::ulid();
                DB::table('fms_ai_messages')->insert(app(DatabasePayloads::class)->protect('fms_ai_messages', $messageId, ['id' => $messageId, 'conversation_id' => $row->id, 'run_id' => $this->run, 'role' => 'assistant', 'content' => $text, 'created_at' => now(), 'updated_at' => now()]));
                DB::table('fms_ai_conversations')->where('id', $row->id)->update(['message_count' => $row->message_count + 1, 'context_bytes' => $row->context_bytes + strlen($text)]);
                DB::table('fms_ai_runs')->where('id', $this->run)->update(app(DatabasePayloads::class)->protect('fms_ai_runs', $this->run, ['usage' => $usage === [] ? null : json_encode($usage, JSON_THROW_ON_ERROR)]));
                self::finish($this->run, 'completed');
            });
        } catch (\Throwable $e) {
            report($e);
            self::finish($this->run, 'failed', 'The response could not be completed. Please try again.');
        }
    }

    private function authorize(): object
    {
        return app(WorkspaceAccess::class)->resolveForWorker($this->principal, $this->workspace, $this->tenant, operation: 'ai.view');
    }

    public static function event(string $run, string $type, array $data): void
    {
        $payload = json_encode($data, JSON_THROW_ON_ERROR);
        if (! app(DatabasePayloads::class)->enabled()) {
            DB::table('fms_ai_events')->insert(['run_id' => $run, 'type' => $type, 'data' => $payload, 'created_at' => now()]);

            return;
        }
        DB::transaction(function () use ($run, $type, $payload): void {
            $id = DB::table('fms_ai_events')->insertGetId(['run_id' => $run, 'type' => $type, 'data' => '', 'created_at' => now()]);
            DB::table('fms_ai_events')->where('id', $id)->update(app(DatabasePayloads::class)->protect('fms_ai_events', $id, ['data' => $payload]));
        });
    }

    public static function finish(string $run, string $state, ?string $error = null): void
    {
        DB::transaction(static function () use ($run, $state, $error): void {
            $row = DB::table('fms_ai_runs')->where('id', $run)->first();
            if (! $row) {
                return;
            }
            $row = app(DatabasePayloads::class)->reveal('fms_ai_runs', $row->id, $row);
            // All transitions serialize on the conversation lock, in the same order as send.
            DB::table('fms_ai_conversations')->where('id', $row->conversation_id)->lockForUpdate()->first();
            if (! DB::table('fms_ai_runs')->where('id', $run)->whereIn('state', ['queued', 'running'])->update(app(DatabasePayloads::class)->protect('fms_ai_runs', $run, ['state' => $state, 'error' => $error, 'updated_at' => now()]))) {
                return;
            }
            if ($row->slot_key) {
                DB::table('fms_ai_slots')->where('key', $row->slot_key)->where('active', '>', 0)->decrement('active');
            }
            if ($state !== 'completed' && $row->partial_text && ! DB::table('fms_ai_messages')->where('run_id', $run)->where('role', 'assistant')->exists()) {
                $messageId = (string) Str::ulid();
                DB::table('fms_ai_messages')->insert(app(DatabasePayloads::class)->protect('fms_ai_messages', $messageId, ['id' => $messageId, 'conversation_id' => $row->conversation_id, 'run_id' => $run, 'role' => 'assistant', 'content' => $row->partial_text, 'created_at' => now(), 'updated_at' => now()]));
                DB::table('fms_ai_conversations')->where('id', $row->conversation_id)->increment('message_count');
                DB::table('fms_ai_conversations')->where('id', $row->conversation_id)->increment('context_bytes', strlen($row->partial_text));
            }
            DB::table('fms_ai_runs')->where('id', $run)->update(['partial_text' => null]);
            DB::table('fms_ai_conversations')->where('id', $row->conversation_id)->where('active_run', $run)->update(['active_run' => null, 'updated_at' => now()]);
            self::event($run, 'done', ['state' => $state, 'error' => $error]);
        });
    }

    public function failed(?\Throwable $exception): void
    {
        self::finish($this->run, 'failed', 'The response was interrupted. Please try again.');
    }
}
