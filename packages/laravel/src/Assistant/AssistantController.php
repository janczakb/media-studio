<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Storage\Encryption\ConversationTitles;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class AssistantController
{
    private function scope(Request $request, string $workspace): array
    {
        abort_unless(app(AssistantConfiguration::class)->available(), 404);
        $principal = Principal::fromUser($request->user());

        return [$principal, app(WorkspaceAccess::class)->resolve($principal, $workspace)];
    }

    private function conversation(Principal $principal, object $scope, string $id, bool $lock = false): object
    {
        $row = DB::table('fms_ai_conversations')->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->where('id', $id)->whereNull('deleted_at')->when($lock, fn ($q) => $q->lockForUpdate())->first();
        abort_if($row === null, 404);
        if ($row->active_run !== null) {
            $run = DB::table('fms_ai_runs')->where('id', $row->active_run)->first();
            if ($run && in_array($run->state, ['queued', 'running'], true) && strtotime($run->updated_at) < time() - 600) {
                RunAssistant::finish($run->id, 'failed', 'The response timed out. Start a new message.');
                $row->active_run = null;
            }
        }

        $row->title = app(ConversationTitles::class)->read($row);

        return $row;
    }

    public function index(Request $request, string $workspace)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $data = $request->validate(['cursor' => ['nullable', 'ulid'], 'search' => ['nullable', 'string', 'max:100']]);
        $query = DB::table('fms_ai_conversations')->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->whereNull('deleted_at');
        $search = trim($data['search'] ?? '');
        if ($search !== '') {
            if (app(DatabasePayloads::class)->enabled()) {
                $tokens = app(ConversationTitles::class)->searchTokens((int) $scope->id, $principal->key(), $search);
                $query->where(function ($q) use ($tokens, $search) {
                    $q->whereIn('id', DB::table('fms_ai_title_tokens')->whereIn('token', $tokens)->select('conversation_id'));
                    if (config('media-studio.encryption.database.allow_plaintext_reads') === true) {
                        $q->orWhere('search_title', 'like', addcslashes(mb_strtolower($search, 'UTF-8'), '%_\\').'%');
                    }
                });
            } else {
                $query->whereRaw("search_title LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($search, 'UTF-8')).'%']);
            }
        }
        $rows = $query->when($data['cursor'] ?? null, fn ($q, $cursor) => $q->where('id', '<', $cursor))->orderByDesc('id')->limit(31)->get(['id', 'title', 'encrypted_title', 'updated_at', 'revision']);
        foreach ($rows as $row) {
            $row->title = app(ConversationTitles::class)->read($row);
            unset($row->encrypted_title);
        }

        return response()->json(['items' => $rows->take(30)->values(), 'nextCursor' => $rows->count() > 30 ? $rows[29]->id : null])->header('Cache-Control', 'private, no-store');
    }

    public function store(Request $request, string $workspace)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $id = (string) Str::ulid();
        DB::transaction(function () use ($id, $scope, $principal): void {
            DB::table('fms_ai_conversations')->insert(['id' => $id, 'workspace_id' => $scope->id, 'principal_key' => $principal->key(), 'title' => '', 'search_title' => '', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_ai_conversations')->where('id', $id)->update(app(ConversationTitles::class)->fields($id, (int) $scope->id, $principal->key(), 'New chat'));
        });

        return response()->json(['id' => $id, 'title' => 'New chat', 'revision' => 1], 201);
    }

    public function show(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $row = $this->conversation($principal, $scope, $conversation);
        $page = $request->validate(['before' => ['nullable', 'ulid', 'prohibits:after'], 'after' => ['nullable', 'ulid']]);
        $query = DB::table('fms_ai_messages')->where('conversation_id', $row->id);
        $messages = (clone $query)->when($page['before'] ?? null, fn ($q, $id) => $q->where('id', '<', $id))->when($page['after'] ?? null, fn ($q, $id) => $q->where('id', '>', $id))
            ->orderBy('id', isset($page['after']) ? 'asc' : 'desc')->limit(30)->get(['id', 'role', 'content', 'run_id'])->sortBy('id')->values();
        $first = $messages->first()?->id;
        $last = $messages->last()?->id;
        $older = $first && (clone $query)->where('id', '<', $first)->exists() ? $first : null;
        $newer = $last && (clone $query)->where('id', '>', $last)->exists() ? $last : null;
        $files = DB::table('fms_ai_attachments')->where('conversation_id', $row->id)->where('principal_key', $principal->key())->whereIn('run_id', $messages->pluck('run_id')->all())->limit(150)->get(['id', 'run_id', 'name', 'bytes', 'mime', 'state'])->groupBy('run_id');
        foreach ($messages as $message) {
            $message->content = app(DatabasePayloads::class)->open('fms_ai_messages', $message->id, 'content', $message->content);
            $message->attachments = $message->role === 'user' ? ($files[$message->run_id] ?? collect())->map(static fn ($file) => ['id' => $file->id, 'name' => $file->name, 'size' => (int) $file->bytes, 'mimeType' => $file->mime, 'available' => $file->state === 'ready'])->all() : [];
        }
        $run = DB::table('fms_ai_runs')->where('conversation_id', $row->id)->orderByDesc('id')->first(['id', 'state', 'usage', 'error', 'model_key']);
        if ($run) {
            $run->actions = DB::table('fms_ai_actions')->where('run_id', $run->id)->whereNull('undone_at')->orderBy('id')->limit(12)->get(['id', 'result'])->map(static fn ($r) => json_decode(app(DatabasePayloads::class)->open('fms_ai_actions', $r->id, 'result', $r->result), true, flags: JSON_THROW_ON_ERROR))->all();
        }
        if ($run) {
            $run = app(DatabasePayloads::class)->reveal('fms_ai_runs', $run->id, $run);
            $run->usage = $run->usage ? json_decode($run->usage, true, flags: JSON_THROW_ON_ERROR) : null;
        }

        return response()->json(['id' => $row->id, 'title' => $row->title, 'revision' => (int) $row->revision, 'messages' => $messages, 'nextCursor' => $older, 'newerCursor' => $newer, 'run' => $run])->header('Cache-Control', 'private, no-store');
    }

    public function send(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $data = $request->validate(['key' => ['required', 'uuid'], 'text' => ['required', 'string', 'max:8000'], 'model' => ['required', 'string', 'max:80'], 'entries' => ['sometimes', 'array', 'max:10'], 'entries.*' => ['required', 'ulid', 'distinct'], 'attachments' => ['sometimes', 'array', 'max:5'], 'attachments.*' => ['required', 'ulid', 'distinct']]);
        abort_if(trim($data['text']) === '', 422, 'Enter a message.');
        $models = app(AssistantConfiguration::class)->models();
        $model = $models[$data['model']] ?? null;
        abort_if($model === null, 422, 'Choose a configured model.');
        // Verify every attachment before accepting the turn. IDs are resolved again in the worker.
        foreach ($data['entries'] ?? [] as $id) {
            $entry = app(Entries::class)->resolve($scope->id, $id);
            abort_if($entry->trashed_at !== null, 404);
            abort_if(app(SourcePolicy::class)->visible($scope, $principal, [$entry]) === [], 404);
            app(SourcePolicy::class)->readable($scope, $principal, $entry);
        }
        [$run, $created] = DB::transaction(function () use ($principal, $scope, $conversation, $data, $model): array {
            $row = $this->conversation($principal, $scope, $conversation, true);
            $previous = DB::table('fms_ai_runs')->where('conversation_id', $row->id)->where('request_key', $data['key'])->first();
            if ($previous) {
                abort_unless(hash_equals($previous->request_hash, hash('sha256', json_encode($data, JSON_THROW_ON_ERROR))), 409, 'The same request key was used with different input.');

                return [$previous->id, false];
            }
            abort_if($row->active_run !== null, 409, 'A response is already in progress.');
            DB::table('fms_ai_slots')->insertOrIgnore(['key' => $principal->key(), 'active' => 0]);
            $slot = DB::table('fms_ai_slots')->where('key', $principal->key())->lockForUpdate()->first();
            abort_if($slot->active >= max(1, min(8, (int) config('media-studio.assistant.max_active_runs', 2))), 429, 'Too many active responses. Wait for one to finish.');
            DB::table('fms_ai_slots')->where('key', $principal->key())->increment('active');
            $id = (string) Str::ulid();
            DB::table('fms_ai_runs')->insert(app(DatabasePayloads::class)->protect('fms_ai_runs', $id, ['id' => $id, 'conversation_id' => $row->id, 'request_key' => $data['key'], 'slot_key' => $principal->key(), 'request_hash' => hash('sha256', json_encode($data, JSON_THROW_ON_ERROR)), 'state' => 'queued', 'model_key' => $data['model'], 'provider' => $model['provider'], 'model' => $model['model'], 'context' => json_encode($data['entries'] ?? [], JSON_THROW_ON_ERROR), 'created_at' => now(), 'updated_at' => now()]));
            app(AssistantAttachments::class)->claim($data['attachments'] ?? [], $scope, $principal, $id, $row->id);
            $messageId = (string) Str::ulid();
            DB::table('fms_ai_messages')->insert(app(DatabasePayloads::class)->protect('fms_ai_messages', $messageId, ['id' => $messageId, 'conversation_id' => $row->id, 'run_id' => $id, 'role' => 'user', 'content' => $data['text'], 'created_at' => now(), 'updated_at' => now()]));
            $titleFields = $row->message_count === 0 && (int) $row->revision === 1
                ? app(ConversationTitles::class)->fields($row->id, (int) $scope->id, $principal->key(), Str::limit(preg_replace('/\s+/u', ' ', trim($data['text'])), 100)) : [];
            DB::table('fms_ai_conversations')->where('id', $row->id)->update($titleFields + ['active_run' => $id, 'revision' => $row->message_count === 0 ? $row->revision + 1 : $row->revision, 'message_count' => $row->message_count + 1, 'context_bytes' => $row->context_bytes + strlen($data['text']), 'updated_at' => now()]);

            return [$id, true];
        });
        if ($created) {
            try {
                RunAssistant::dispatch($run, $principal, $workspace, TenantIdentity::fromWorkspace($scope))
                    ->onConnection(config('media-studio.assistant.connection') ?? config('queue.default'))->onQueue(config('media-studio.assistant.queue', 'media-studio-ai'));
            } catch (\Throwable $e) {
                RunAssistant::finish($run, 'failed', 'The response could not be queued.');
                report($e);
            }
        }

        return response()->json(['id' => $run], 202);
    }

    public function rename(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1'], 'title' => ['required', 'string', 'max:160', 'regex:/\A[^\p{C}]+\z/u']]);
        $title = trim($data['title']);
        abort_if($title === '', 422);

        return DB::transaction(function () use ($principal, $scope, $conversation, $data, $title) {
            $row = $this->conversation($principal, $scope, $conversation, true);
            abort_unless((int) $row->revision === $data['revision'], 409, 'This conversation changed. Refresh its history before editing.');
            DB::table('fms_ai_conversations')->where('id', $row->id)->update(app(ConversationTitles::class)->fields($row->id, (int) $scope->id, $principal->key(), $title) + ['revision' => $row->revision + 1, 'updated_at' => now()]);

            return response()->json(['id' => $row->id, 'title' => $title, 'revision' => $row->revision + 1]);
        });
    }

    public function remove(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);

        return DB::transaction(function () use ($principal, $scope, $conversation, $data) {
            $row = $this->conversation($principal, $scope, $conversation, true);
            abort_unless((int) $row->revision === $data['revision'], 409, 'This conversation changed. Refresh its history before deleting.');
            if ($row->active_run) {
                RunAssistant::finish($row->active_run, 'cancelled');
            }
            DB::table('fms_ai_conversations')->where('id', $row->id)->update(['deleted_at' => now(), 'revision' => $row->revision + 1, 'updated_at' => now()]);

            return response()->json(['id' => $row->id, 'title' => $row->title, 'revision' => $row->revision + 1]);
        });
    }

    public function restoreConversation(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $data = $request->validate(['revision' => ['required', 'integer', 'min:1']]);
        $updated = DB::table('fms_ai_conversations')->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->where('id', $conversation)->whereNotNull('deleted_at')->where('revision', $data['revision'])->update(['deleted_at' => null, 'revision' => $data['revision'] + 1, 'updated_at' => now()]);
        abort_unless($updated === 1, 409, 'This conversation is unavailable or changed.');
        $row = $this->conversation($principal, $scope, $conversation);

        return response()->json(['id' => $row->id, 'title' => $row->title, 'revision' => (int) $row->revision]);
    }

    public function upload(Request $request, string $workspace)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $request->validate(['file' => ['required', 'file', 'max:8192']]);

        return response()->json(app(AssistantAttachments::class)->stage($scope, $principal, $request->file('file')), 201)->header('Cache-Control', 'private, no-store');
    }

    public function discardAttachment(Request $request, string $workspace, string $attachment)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        DB::transaction(function () use ($principal, $scope, $attachment): void {
            $row = DB::table('fms_ai_attachments')->where('id', $attachment)->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->whereNull('run_id')->lockForUpdate()->first();
            abort_if($row === null, 404);
            DB::table('fms_ai_attachments')->where('id', $row->id)->update(['state' => 'staging', 'expires_at' => now()->subHour()]);
        });
        app(AssistantAttachments::class)->prune();

        return response()->json(['ok' => true]);
    }

    public function cancel(Request $request, string $workspace, string $conversation)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        DB::transaction(function () use ($principal, $scope, $conversation): void {
            $row = $this->conversation($principal, $scope, $conversation, true);
            if ($row->active_run) {
                RunAssistant::finish($row->active_run, 'cancelled');
            }
        });

        return response()->json(['ok' => true]);
    }

    public function events(Request $request, string $workspace, string $conversation, string $run)
    {
        [$principal, $scope] = $this->scope($request, $workspace);
        $this->conversation($principal, $scope, $conversation);
        abort_unless(DB::table('fms_ai_runs')->where('conversation_id', $conversation)->where('id', $run)->exists(), 404);
        $data = $request->validate(['after' => ['nullable', 'integer', 'min:0']]);
        $after = (int) ($data['after'] ?? 0);

        return response()->stream(function () use ($workspace, $principal, $scope, $run, $after): void {
            $end = microtime(true) + 25;
            $tenant = TenantIdentity::fromWorkspace($scope);
            do {
                app(WorkspaceAccess::class)->resolveForWorker($principal, $workspace, $tenant, operation: 'ai.view');
                $events = DB::table('fms_ai_events')->where('run_id', $run)->where('id', '>', $after)->orderBy('id')->limit(100)->get();
                foreach ($events as $event) {
                    $after = $event->id;
                    $event->data = app(DatabasePayloads::class)->open('fms_ai_events', $event->id, 'data', $event->data);
                    echo 'id: '.$event->id."\nevent: ".$event->type."\ndata: ".$event->data."\n\n";
                }
                echo ": heartbeat\n\n";
                if (ob_get_level()) {
                    ob_flush();
                }
                flush();
                if ($events->count() < 100 && ! in_array(DB::table('fms_ai_runs')->where('id', $run)->value('state'), ['queued', 'running'], true)) {
                    break;
                }
                if (connection_aborted()) {
                    break;
                }
                usleep(250000);
            } while (microtime(true) < $end);
        }, 200, ['Content-Type' => 'text/event-stream', 'Cache-Control' => 'private, no-store', 'X-Accel-Buffering' => 'no']);
    }
}
