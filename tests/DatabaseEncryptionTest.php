<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Laravel\Ai\AiServiceProvider;
use UseFlex\MediaCore\Encryption\EncryptionFailure;
use UseFlex\MediaStudio\Assistant\AssistantAttachments;
use UseFlex\MediaStudio\Assistant\AssistantGateway;
use UseFlex\MediaStudio\Assistant\RunAssistant;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Storage\Encryption\DatabaseEncryptionMigration;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class DatabaseEncryptionTest extends TestCase
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
        config(['media-studio.encryption.mode' => 'application', 'media-studio.encryption.database.enabled' => true,
            'media-studio.encryption.keys' => ['v1' => 'base64:'.base64_encode(str_repeat('k', 32))],
            'media-studio.assistant.enabled' => true, 'queue.default' => 'database', 'queue.connections.database.driver' => 'database',
            'media-studio.assistant.models' => ['test' => ['label' => 'Test', 'provider' => 'openai', 'model' => 'fixture']]]);
        Queue::fake();
    }

    public function test_chat_titles_messages_partial_output_events_and_history_are_protected(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $chat = $this->postJson($path)->assertCreated()->json('id');
        $run = $this->postJson($path.'/'.$chat.'/messages', ['key' => (string) Str::uuid(), 'text' => 'Confidential project budget', 'model' => 'test'])->assertAccepted()->json('id');
        $gateway = new class implements AssistantGateway
        {
            public function stream(array $model, array $messages, string $prompt, array $context): iterable
            {
                DatabaseEncryptionTest::assertSame('Confidential project budget', $prompt);
                yield ['type' => 'delta', 'text' => str_repeat('Private response ', 180)];
            }
        };
        Queue::pushed(RunAssistant::class)->first()->handle($gateway);
        foreach (DB::table('fms_ai_messages')->get() as $row) {
            self::assertStringNotContainsString('Confidential', $row->content);
            self::assertStringNotContainsString('Private response', $row->content);
            self::assertStringContainsString('__fms_encrypted_v1', $row->content);
        }
        $row = DB::table('fms_ai_conversations')->where('id', $chat)->first();
        self::assertSame('', $row->title);
        self::assertSame('', $row->search_title);
        self::assertStringNotContainsString('Confidential', $row->encrypted_title);
        self::assertGreaterThan(0, DB::table('fms_ai_title_tokens')->where('conversation_id', $chat)->count());
        foreach (DB::table('fms_ai_events')->where('run_id', $run)->get() as $event) {
            self::assertStringNotContainsString('Private response', $event->data);
        }
        $this->getJson($path.'/'.$chat)->assertOk()->assertJsonPath('title', 'Confidential project budget')->assertJsonPath('messages.0.content', 'Confidential project budget')->assertJsonPath('run.state', 'completed');
        $this->getJson($path.'?search=Confidential')->assertOk()->assertJsonPath('items.0.id', $chat);
        $this->getJson($path.'?search=Another')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_comments_and_note_previews_have_no_duplicate_plaintext_body(): void
    {
        $workspace = $this->workspace();
        $note = $this->postJson($this->url($workspace, 'notes'), ['key' => 'encrypted-note-fixture', 'title' => 'A note', 'background' => '#ffffff',
            'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Private note text']]]]]])->assertCreated()->assertJsonPath('document.content.0.content.0.text', 'Private note text')->json('entry');
        $body = ['key' => 'encrypted-comment-fixture', 'body' => 'Private comment text'];
        $comment = $this->postJson($this->url($workspace, 'entries/'.$note['id'].'/comments'), $body)->assertCreated()->assertJsonPath('body', 'Private comment text')->json();
        $this->postJson($this->url($workspace, 'entries/'.$note['id'].'/comments'), $body)->assertCreated()->assertJsonPath('id', $comment['id']);
        foreach (DB::table('fms_comments')->get() as $row) {
            self::assertStringNotContainsString('Private comment text', $row->body);
        }
        self::assertStringNotContainsString('Private note text', DB::table('fms_notes')->first()->document);
        self::assertStringNotContainsString('Private note text', DB::table('fms_entries')->first()->note_excerpt);
        $this->getJson($this->url($workspace, 'entries'))->assertOk()->assertJsonPath('items.0.noteExcerpt', 'Private note text');
        $this->getJson($this->url($workspace, 'entries/'.$note['id'].'/comments'))->assertOk()->assertJsonPath('items.0.body', 'Private comment text');
    }

    public function test_field_binding_rotation_and_plaintext_downgrade_fail_closed(): void
    {
        $cipher = app(DatabasePayloads::class);
        $sealed = $cipher->seal('fms_ai_messages', 'first', 'content', 'secret');
        self::assertSame('secret', $cipher->open('fms_ai_messages', 'first', 'content', $sealed));
        foreach (['second', 'another-workspace-record'] as $identity) {
            try {
                $cipher->open('fms_ai_messages', $identity, 'content', $sealed);
                self::fail('Ciphertext substitution accepted');
            } catch (EncryptionFailure) {
                self::assertTrue(true);
            }
        }
        $this->expectException(EncryptionFailure::class);
        $cipher->open('fms_ai_messages', 'first', 'content', 'plaintext');
    }

    public function test_existing_payloads_migrate_in_resumable_verified_batches(): void
    {
        config(['media-studio.encryption.database.enabled' => false]);
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $chat = $this->postJson($path)->assertCreated()->json('id');
        $this->postJson($path.'/'.$chat.'/messages', ['key' => (string) Str::uuid(), 'text' => 'Legacy confidential title', 'model' => 'test'])->assertAccepted();
        config(['media-studio.encryption.database.enabled' => true]);
        $migration = app(DatabaseEncryptionMigration::class);
        $id = $migration->start();
        $first = $migration->step($id, 1);
        self::assertSame('pending', $first->state);
        self::assertSame(1, (int) $first->processed);
        $sealedTitle = DB::table('fms_ai_conversations')->where('id', $chat)->value('encrypted_title');
        for ($i = 0; $i < 20; $i++) {
            $operation = $migration->step($id, 1);
            if ($operation->state === 'completed') {
                break;
            }
        }
        self::assertSame('completed', $operation->state);
        self::assertSame($sealedTitle, DB::table('fms_ai_conversations')->where('id', $chat)->value('encrypted_title'));
        $this->getJson($path.'/'.$chat)->assertOk()->assertJsonPath('messages.0.content', 'Legacy confidential title');
        $this->getJson($path.'?search=Legacy')->assertOk()->assertJsonPath('items.0.id', $chat);
    }

    public function test_corrupted_database_ciphertext_does_not_advance_migration_checkpoint(): void
    {
        $workspace = $this->workspace();
        $chat = $this->postJson($this->url($workspace, 'assistant/conversations'))->assertCreated()->json('id');
        DB::table('fms_ai_conversations')->where('id', $chat)->update(['encrypted_title' => '{"__fms_encrypted_v1":"v1:AAAA"}']);
        $migration = app(DatabaseEncryptionMigration::class);
        $id = $migration->start();
        try {
            $migration->step($id);
            self::fail('Corrupt title was accepted');
        } catch (EncryptionFailure) {
            $state = DB::table('fms_encryption_operations')->where('id', $id)->first();
            self::assertSame('failed', $state->state);
            self::assertSame(0, (int) $state->processed);
            self::assertNull(json_decode($state->checkpoint, true)['after']);
        }
    }

    public function test_assistant_attachments_are_encrypted_and_decrypted_in_bounded_memory(): void
    {
        config(['media-studio.assistant.attachment_directory' => storage_path('framework/testing/encrypted-attachments-'.getmypid())]);
        $workspace = $this->workspace();
        $scope = DB::table('fms_workspaces')->where('public_id', $workspace)->first();
        $principal = Principal::fromUser(auth()->user());
        $files = app(AssistantAttachments::class);
        $attachment = $files->stage($scope, $principal, UploadedFile::fake()->createWithContent('private.txt', 'Secret attachment bytes'));
        try {
            self::assertStringNotContainsString('Secret attachment bytes', file_get_contents($files->path($attachment['id'])));
            $chat = $this->postJson($this->url($workspace, 'assistant/conversations'))->assertCreated()->json('id');
            $run = $this->postJson($this->url($workspace, 'assistant/conversations/'.$chat.'/messages'), ['key' => (string) Str::uuid(), 'text' => 'Read the attachment', 'model' => 'test', 'attachments' => [$attachment['id']]])->assertAccepted()->json('id');
            $providerFiles = $files->forProvider($scope, $principal, $run);
            self::assertSame('Secret attachment bytes', $providerFiles[0]['content']);
            self::assertArrayNotHasKey('path', $providerFiles[0]);
        } finally {
            if (is_file($files->path($attachment['id']))) {
                unlink($files->path($attachment['id']));
            }
        }
    }

    public function test_interrupted_response_keeps_only_encrypted_partial_output_and_replays_plaintext(): void
    {
        $workspace = $this->workspace();
        $path = $this->url($workspace, 'assistant/conversations');
        $chat = $this->postJson($path)->assertCreated()->json('id');
        $this->postJson($path.'/'.$chat.'/messages', ['key' => (string) Str::uuid(), 'text' => 'An interrupted reply', 'model' => 'test'])->assertAccepted();
        $gateway = new class implements AssistantGateway
        {
            public function stream(array $model, array $messages, string $prompt, array $context): iterable
            {
                yield ['type' => 'delta', 'text' => str_repeat('secret partial ', 200)];
                throw new \RuntimeException('Public test interruption');
            }
        };
        Queue::pushed(RunAssistant::class)->first()->handle($gateway);
        $this->getJson($path.'/'.$chat)->assertOk()->assertJsonPath('run.state', 'failed')->assertJsonPath('messages.1.content', str_repeat('secret partial ', 200));
        self::assertStringNotContainsString('secret partial', DB::table('fms_ai_messages')->where('role', 'assistant')->value('content'));
        self::assertNull(DB::table('fms_ai_runs')->value('partial_text'));
    }
}
