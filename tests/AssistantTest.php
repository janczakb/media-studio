<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\AiServiceProvider;
use Laravel\Ai\Tools\Request;
use UseFlex\MediaStudio\Assistant\AssistantAttachments;
use UseFlex\MediaStudio\Assistant\AssistantGateway;
use UseFlex\MediaStudio\Assistant\AssistantToolContext;
use UseFlex\MediaStudio\Assistant\LaravelAssistantGateway;
use UseFlex\MediaStudio\Assistant\RunAssistant;
use UseFlex\MediaStudio\Assistant\StudioAgent;
use UseFlex\MediaStudio\Assistant\StudioApplicationTool;
use UseFlex\MediaStudio\Assistant\StudioLibraryTool;
use UseFlex\MediaStudio\Identity\Principal;

final class AssistantTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [...parent::getPackageProviders($app), AiServiceProvider::class];
    }

    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/assistant-migrations/*.php') as $migration) {
            (require $migration)->up();
        }
        config(['media-studio.assistant.enabled' => true, 'queue.default' => 'database', 'queue.connections.database.driver' => 'database', 'media-studio.assistant.models' => ['fast' => ['label' => 'Fast', 'provider' => 'openai', 'model' => 'test-model']]]);
        config(['media-studio.assistant.attachment_directory' => storage_path('framework/testing/assistant-'.getmypid())]);
        Queue::fake();
    }

    public function test_persistent_conversation_stream_and_idempotent_delivery(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $id = $this->postJson($path)->assertCreated()->json('id');
        $body = ['key' => (string) Str::uuid(), 'text' => 'Hello', 'model' => 'fast'];
        $run = $this->postJson("$path/$id/messages", $body)->assertAccepted()->json('id');
        $this->postJson("$path/$id/messages", $body)->assertAccepted()->assertJsonPath('id', $run);
        Queue::assertPushed(RunAssistant::class, 1);
        $this->postJson("$path/$id/messages", [...$body, 'key' => (string) Str::uuid()])->assertConflict();
        $gateway = new class implements AssistantGateway
        {
            public int $calls = 0;

            public function stream(array $model, array $messages, string $prompt, array $context): iterable
            {
                $this->calls++;
                yield ['type' => 'delta', 'text' => 'Hello '];
                yield ['type' => 'delta', 'text' => 'world'];
                yield ['type' => 'usage', 'usage' => ['input_tokens' => 4, 'output_tokens' => 2]];
            }
        };
        $job = Queue::pushed(RunAssistant::class)->first();
        $job->handle($gateway);
        $job->handle($gateway);
        $this->assertSame(1, $gateway->calls);
        $this->getJson("$path/$id")->assertOk()->assertJsonPath('messages.1.content', 'Hello world')->assertJsonPath('run.state', 'completed')->assertJsonPath('run.usage.output_tokens', 2);
        $this->getJson($path)->assertOk()->assertJsonPath('items.0.title', 'Hello');
        $response = $this->get("$path/$id/runs/$run/events")->assertOk();
        $this->assertStringContainsString('Hello world', $response->streamedContent());
    }

    public function test_installed_sdk_adapter_normalizes_real_sdk_stream_events(): void
    {
        StudioAgent::fake(['A streamed answer.']);
        $events = iterator_to_array(app(LaravelAssistantGateway::class)->stream(['provider' => 'openai', 'model' => 'test-model'], [], 'Hello', []));
        $this->assertSame('A streamed answer.', implode('', array_column(array_filter($events, fn ($e) => $e['type'] === 'delta'), 'text')));
        StudioAgent::assertPrompted('Hello');
    }

    public function test_library_tools_reuse_real_writers_with_revision_and_receipt_guards(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Original'])->assertCreated()->json();
        $destination = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Destination'])->assertCreated()->json();
        $path = $this->url($workspace, 'assistant/conversations');
        $id = $this->postJson($path)->assertCreated()->json('id');
        $run = $this->postJson("$path/$id/messages", ['key' => (string) Str::uuid(), 'text' => 'Rename Original to Updated', 'model' => 'fast'])->assertAccepted()->json('id');
        DB::table('fms_ai_runs')->where('id', $run)->update(['state' => 'running']);
        $tool = new StudioLibraryTool($run, Principal::fromUser(auth()->user()), $workspace, null);
        $args = ['action' => 'rename', 'entry' => $folder['id'], 'parent' => '', 'query' => '', 'name' => 'Updated', 'revision' => $folder['revision']];
        $call = new Request($args, 'call-rename');
        $first = $tool->handle($call);
        $this->assertTrue(json_decode($first, true)['ok']);
        $this->assertSame($first, $tool->handle($call));
        $this->assertSame($folder['revision'] + 1, (int) DB::table('fms_entries')->where('public_id', $folder['id'])->value('revision'));
        $this->assertSame(409, json_decode($tool->handle(new Request([...$args, 'name' => 'Stale change'], 'stale-call')), true)['status']);
        $move = [...$args, 'action' => 'move', 'parent' => $destination['id'], 'revision' => $folder['revision'] + 1];
        $this->assertTrue(json_decode($tool->handle(new Request($move, 'call-move')), true)['ok']);
        $this->assertSame((int) DB::table('fms_entries')->where('public_id', $destination['id'])->value('id'), (int) DB::table('fms_entries')->where('public_id', $folder['id'])->value('parent_id'));
        Gate::define('studio.files.update', static fn () => false);
        $this->assertSame(403, json_decode($tool->handle(new Request([...$args, 'revision' => $folder['revision'] + 2], 'forbidden')), true)['status']);
        $this->assertSame(2, DB::table('fms_ai_tool_receipts')->count());
        Gate::define('studio.files.update', static fn () => true);
        $withoutProviderId = new Request([...$args, 'name' => 'Final', 'revision' => $folder['revision'] + 2]);
        $result = $tool->handle($withoutProviderId);
        $this->assertTrue(json_decode($result, true)['ok']);
        $this->assertSame($result, $tool->handle($withoutProviderId));
        $receipt = json_decode($result, true);
        $undo = $this->url($workspace, 'assistant/actions/'.$receipt['actionId'].'/undo');
        Gate::define('studio.files.update', static fn () => false);
        $this->postJson($undo)->assertForbidden();
        Gate::define('studio.files.update', static fn () => true);
        $this->postJson($undo)->assertOk();
        $this->assertSame('Updated', DB::table('fms_entries')->where('public_id', $folder['id'])->value('name'));
        $this->postJson($undo)->assertOk();
    }

    public function test_application_actions_and_undo_reuse_domain_permissions_and_revisions(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Action fixture'])->assertCreated()->json();
        $base = $this->url($workspace, 'assistant');
        $chat = $this->postJson("$base/conversations")->assertCreated()->json('id');
        $run = $this->postJson("$base/conversations/$chat/messages", ['key' => (string) Str::uuid(), 'text' => 'Manage fixture', 'model' => 'fast'])->assertAccepted()->json('id');
        DB::table('fms_ai_runs')->where('id', $run)->update(['state' => 'running']);
        $tool = new StudioApplicationTool(new AssistantToolContext($run, Principal::fromUser(auth()->user()), $workspace, null));
        $call = fn ($action, $parameters = [], $entry = null) => json_decode($tool->handle(new Request(['action' => $action, 'entry' => $entry ?? $folder['id'], 'parameters' => json_encode((object) $parameters)])), true);
        $open = $call('open');
        $this->assertSame('open', $open['command']);
        $this->assertFalse($open['undoable']);
        $this->assertSame($folder['id'], $open['entry']['id']);
        $favorite = $call('favorite', ['starred' => true]);
        $this->assertTrue($favorite['undoable']);
        $this->assertSame($favorite, $call('favorite', ['starred' => true]));
        $this->postJson("$base/actions/{$favorite['actionId']}/undo")->assertOk();
        $this->postJson("$base/actions/{$favorite['actionId']}/undo")->assertOk();
        $this->assertNull(DB::table('fms_personal_entries')->value('starred_at'));
        $tags = $call('tags', ['revision' => $folder['revision'], 'tags' => [['name' => 'Fixture', 'locale' => 'en']]]);
        $this->assertTrue($tags['ok']);
        $this->postJson("$base/actions/{$tags['actionId']}/undo")->assertOk();
        $this->assertSame(0, DB::table('fms_entry_tags')->count());
        $comment = $call('comment', ['body' => 'Actual comment']);
        $this->assertSame('Actual comment', $call('comments')['items'][0]['body']);
        $resolved = $call('resolve_comment', ['comment' => $comment['comment']['id'], 'revision' => 1, 'resolved' => true]);
        $this->assertTrue($resolved['comment']['resolved']);
        $this->postJson("$base/actions/{$comment['actionId']}/undo")->assertConflict();
        $this->postJson("$base/actions/{$resolved['actionId']}/undo")->assertOk();
        $share = $call('create_share', ['title' => 'Fixture link', 'entryIds' => [$folder['id']], 'allowDownload' => true, 'password' => 'test-only-password', 'collectAnalytics' => true]);
        $this->assertTrue($share['ok']);
        $this->assertStringContainsString('/s/', $share['share']['url']);
        $this->assertStringNotContainsString('test-only-password', DB::table('fms_ai_actions')->where('id', $share['actionId'])->value('result'));
        $this->postJson("$base/actions/{$share['actionId']}/undo")->assertOk();
        $this->assertNotNull(DB::table('fms_shares')->value('revoked_at'));
        $created = $call('create_folder', ['name' => 'Created', 'parent' => $folder['id']]);
        $this->postJson("$base/actions/{$created['actionId']}/undo")->assertOk();
        $this->assertNotNull(DB::table('fms_entries')->where('public_id', $created['entry']['id'])->value('trashed_at'));
        Gate::define('studio.comments.create', static fn () => false);
        $this->assertSame(403, $call('comment', ['body' => 'Denied'])['status']);
        $other = $this->workspace();
        $this->postJson($this->url($other, "assistant/actions/{$tags['actionId']}/undo"))->assertNotFound();
        $this->assertGreaterThanOrEqual(7, DB::table('fms_ai_events')->where('type', 'action')->count());
    }

    public function test_message_cursor_pages_and_bounded_context_allow_long_saved_chats(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $chat = $this->postJson($path)->assertCreated()->json('id');
        $ids = [];
        for ($i = 0; $i < 125; $i++) {
            $run = (string) Str::ulid();
            $id = (string) Str::ulid();
            $ids[] = $id;
            DB::table('fms_ai_runs')->insert(['id' => $run, 'conversation_id' => $chat, 'request_key' => (string) Str::uuid(), 'request_hash' => hash('sha256', (string) $i), 'state' => 'completed', 'model_key' => 'fast', 'provider' => 'openai', 'model' => 'test-model', 'context' => '[]', 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_ai_messages')->insert(['id' => $id, 'conversation_id' => $chat, 'run_id' => $run, 'role' => $i % 2 ? 'assistant' : 'user', 'content' => 'Message '.$i, 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('fms_ai_conversations')->where('id', $chat)->update(['message_count' => 125, 'context_bytes' => 999999]);
        foreach ([
            ['SELECT id FROM fms_ai_messages WHERE conversation_id = ? AND id < ? ORDER BY id DESC LIMIT 30', [$chat, $ids[100]], 'fms_ai_messages_page'],
            ['SELECT id FROM fms_ai_runs WHERE conversation_id = ? ORDER BY id DESC LIMIT 1', [$chat], 'fms_ai_latest_run'],
        ] as [$sql, $bindings, $expectedIndex]) {
            $plan = json_encode(DB::select('EXPLAIN QUERY PLAN '.$sql, $bindings));
            $this->assertStringContainsString($expectedIndex, $plan);
            $this->assertStringNotContainsString('USE TEMP B-TREE', $plan);
        }
        $page = $this->getJson("$path/$chat")->assertOk()->assertJsonCount(30, 'messages')->assertJsonPath('messages.0.content', 'Message 95')->assertJsonPath('messages.29.content', 'Message 124')->assertJsonPath('newerCursor', null)->json();
        $older = $this->getJson("$path/$chat?before=".$page['nextCursor'])->assertOk()->assertJsonPath('messages.0.content', 'Message 65')->assertJsonPath('messages.29.content', 'Message 94')->json();
        $this->getJson("$path/$chat?after=".$older['newerCursor'])->assertOk()->assertJsonPath('messages.0.content', 'Message 95')->assertJsonPath('messages.29.content', 'Message 124');
        $this->getJson("$path/$chat?before=invalid")->assertUnprocessable();
        $this->getJson("$path/$chat?before={$ids[0]}&after={$ids[1]}")->assertUnprocessable();
        $this->postJson("$path/$chat/messages", ['key' => (string) Str::uuid(), 'text' => 'Newest question', 'model' => 'fast'])->assertAccepted();
        $gateway = new class implements AssistantGateway
        {
            public array $captured = [];

            public function stream(array $model, array $messages, string $prompt, array $context): iterable
            {
                $this->captured = [$messages, $prompt, $context];
                yield ['type' => 'delta', 'text' => 'Latest answer'];
            }
        };
        Queue::pushed(RunAssistant::class)->first()->handle($gateway);
        $this->assertSame('Newest question', $gateway->captured[1]);
        $this->assertLessThanOrEqual(58, count($gateway->captured[0]));
        $this->assertTrue($gateway->captured[2]['conversationContextIsWindowed']);
        $this->assertSame('Message 67', $gateway->captured[0][0]['content']);
        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->getJson("$path/$chat?before={$ids[100]}")->assertNotFound();
    }

    public function test_chat_retention_is_optional_bounded_and_skips_active_chats(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $old = $this->postJson($path)->assertCreated()->json('id');
        $active = $this->postJson($path)->assertCreated()->json('id');
        DB::table('fms_ai_conversations')->whereIn('id', [$old, $active])->update(['updated_at' => now()->subDays(100)]);
        DB::table('fms_ai_conversations')->where('id', $active)->update(['active_run' => (string) Str::ulid()]);
        $this->artisan('media-studio:assistant:maintain')->assertSuccessful();
        $this->assertNull(DB::table('fms_ai_conversations')->where('id', $old)->value('deleted_at'));
        config(['media-studio.assistant.retention.enabled' => true]);
        $this->artisan('media-studio:assistant:maintain')->assertSuccessful();
        $this->assertNotNull(DB::table('fms_ai_conversations')->where('id', $old)->value('deleted_at'));
        $this->assertNull(DB::table('fms_ai_conversations')->where('id', $active)->value('deleted_at'));
        DB::table('fms_ai_conversations')->where('id', $old)->update(['deleted_at' => now()->subDays(31)]);
        $this->artisan('media-studio:assistant:maintain')->assertSuccessful();
        $this->assertFalse(DB::table('fms_ai_conversations')->where('id', $old)->exists());
        $this->assertTrue(DB::table('fms_ai_conversations')->where('id', $active)->exists());
    }

    public function test_profile_persistence_revision_and_principal_isolation(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/profile');
        $this->getJson($path)->assertOk()->assertJsonPath('revision', 0);
        $character = ['version' => 1, 'personality' => 'calm', 'intensity' => 1, 'trackPointer' => true,
            'shape' => 'hexagon', 'color' => '#ffd37b', 'ink' => '#172739', 'eyes' => 'smile', 'mouth' => 'smile',
            'brows' => 'none', 'accessory' => 'none', 'hair' => 'none', 'hairColor' => '#172739', 'cheeks' => true,
            'eyeSpacing' => 26, 'eyeSize' => 1, 'faceY' => 0];
        $body = ['revision' => 0, 'name' => 'Nova', 'character' => $character];
        $this->putJson($path, $body)->assertOk()->assertJsonPath('revision', 1);
        $this->getJson($path)->assertOk()->assertJsonPath('name', 'Nova')->assertJsonPath('character.shape', 'hexagon');
        $this->putJson($path, $body)->assertConflict();
        $this->putJson($path, [...$body, 'revision' => 1, 'character' => [...$character, 'color' => 'url(evil)']])->assertUnprocessable();
        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->getJson($path)->assertNotFound();
    }

    public function test_global_principal_admission_slots_are_released_after_cancel(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $ids = [];
        for ($i = 0; $i < 3; $i++) {
            $ids[] = $this->postJson($path)->assertCreated()->json('id');
        }
        $message = fn () => ['key' => (string) Str::uuid(), 'text' => 'Hello', 'model' => 'fast'];
        $this->postJson("$path/{$ids[0]}/messages", $message())->assertAccepted();
        $this->postJson("$path/{$ids[1]}/messages", $message())->assertAccepted();
        $this->postJson("$path/{$ids[2]}/messages", $message())->assertStatus(429);
        $this->postJson("$path/{$ids[0]}/cancel")->assertOk();
        $this->postJson("$path/{$ids[2]}/messages", $message())->assertAccepted();
        $this->assertSame(2, (int) DB::table('fms_ai_slots')->value('active'));
    }

    public function test_upload_is_private_scoped_bounded_and_claimed_once(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant');
        $upload = UploadedFile::fake()->createWithContent('brief.txt', 'A short test brief.');
        $file = $this->postJson("$path/attachments", ['file' => $upload])->assertCreated()->json();
        $this->assertSame(19, $file['size']);
        $this->assertFileExists(app(AssistantAttachments::class)->path($file['id']));
        $chat = $this->postJson("$path/conversations")->assertCreated()->json('id');
        $message = ['key' => (string) Str::uuid(), 'text' => 'Analyze my attachment', 'model' => 'fast', 'attachments' => [$file['id']]];
        $run = $this->postJson("$path/conversations/$chat/messages", $message)->assertAccepted()->json('id');
        $this->getJson("$path/conversations/$chat")->assertOk()->assertJsonPath('messages.0.attachments.0.name', 'brief.txt');
        $this->postJson("$path/conversations/$chat/cancel")->assertOk();
        $this->postJson("$path/conversations/$chat/messages", [...$message, 'key' => (string) Str::uuid()])->assertNotFound();
        $this->assertSame($run, DB::table('fms_ai_attachments')->where('id', $file['id'])->value('run_id'));
        DB::table('fms_ai_attachments')->where('id', $file['id'])->update(['expires_at' => now()->subHour()]);
        app(AssistantAttachments::class)->prune();
        $this->assertFileDoesNotExist(app(AssistantAttachments::class)->path($file['id']));
        $this->assertSame('expired', DB::table('fms_ai_attachments')->where('id', $file['id'])->value('state'));
    }

    public function test_history_search_rename_soft_delete_and_restore_are_private_and_revision_guarded(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $id = $this->postJson($path)->assertCreated()->json('id');
        $this->patchJson("$path/$id", ['title' => 'Design review', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->patchJson("$path/$id", ['title' => 'Stale', 'revision' => 1])->assertConflict();
        $this->getJson($path.'?search=design')->assertOk()->assertJsonPath('items.0.title', 'Design review');
        $this->getJson($path.'?search=other')->assertOk()->assertJsonCount(0, 'items');
        $this->deleteJson("$path/$id", ['revision' => 2])->assertOk()->assertJsonPath('revision', 3);
        $this->getJson("$path/$id")->assertNotFound();
        $this->getJson($path)->assertOk()->assertJsonCount(0, 'items');
        $this->postJson("$path/$id/restore", ['revision' => 3])->assertOk()->assertJsonPath('revision', 4);
        $this->getJson("$path/$id")->assertOk()->assertJsonPath('title', 'Design review');
        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->patchJson("$path/$id", ['title' => 'Wrong owner', 'revision' => 4])->assertNotFound();
        $this->deleteJson("$path/$id", ['revision' => 4])->assertNotFound();
    }

    public function test_ownership_revocation_cancellation_and_disabled_mode(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $id = $this->postJson($path)->assertCreated()->json('id');
        $this->postJson("$path/$id/messages", ['key' => (string) Str::uuid(), 'text' => 'Hello', 'model' => 'unknown'])->assertUnprocessable();
        $this->postJson("$path/$id/messages", ['key' => (string) Str::uuid(), 'text' => 'Hello', 'model' => 'fast'])->assertAccepted();
        $job = Queue::pushed(RunAssistant::class)->first();
        $this->postJson("$path/$id/cancel")->assertOk();
        $gateway = new class implements AssistantGateway
        {
            public function stream(array $model, array $messages, string $prompt, array $context): iterable
            {
                throw new \RuntimeException('Must not run');
                yield [];
            }
        };
        $job->handle($gateway);
        $this->getJson("$path/$id")->assertJsonPath('run.state', 'cancelled');
        $this->actingAs(new GenericUser(['id' => 'other-user']));
        $this->getJson("$path/$id")->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        Gate::define('studio.ai.view', static fn () => false);
        $this->getJson("$path/$id")->assertForbidden();
        Gate::define('studio.ai.view', static fn () => true);
        config(['media-studio.assistant.enabled' => false]);
        $this->getJson($path)->assertNotFound();
        $this->getJson('/media-studio/api/v1/ai')->assertJsonPath('assistant.enabled', false);
    }
}
