<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Http\SettingsController;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\FileCategories;

final class StudioWorkspaceSettingsTest extends TestCase
{
    public function test_workspace_policy_matches_file_filters_and_verifies_upload_content(): void
    {
        $workspace = $this->workspace();
        $settings = $this->url($workspace, 'settings');
        $this->getJson($settings)->assertOk()->assertJsonPath('canManage', true)->assertJsonPath('chunks', false)
            ->assertJsonFragment(['extensions' => config('media-studio.browse.extensions', FileCategories::extensions())]);
        $this->patchJson($settings, ['revision' => 0, 'extensions' => ['svg'], 'chunks' => true])->assertUnprocessable();
        $this->patchJson($settings, ['revision' => 0, 'extensions' => ['exe'], 'chunks' => false])->assertUnprocessable();
        $this->patchJson($settings, ['revision' => 0, 'extensions' => ['svg', 'wav', 'mov'], 'chunks' => false])
            ->assertOk()->assertJsonPath('revision', 1);
        $this->patchJson($settings, ['revision' => 0, 'extensions' => [], 'chunks' => false])->assertConflict();
        $file = tmpfile();
        try {
            fwrite($file, '<?xml version="1.0"?><svg xmlns="http://www.w3.org/2000/svg" width="32" height="32"><circle r="8"/></svg>');
            $path = stream_get_meta_data($file)['uri'];
            $response = $this->withHeader('Idempotency-Key', 'test-svg-0000001')->postJson($this->url($workspace, 'uploads'), [
                'file' => new UploadedFile($path, 'outline.svg', null, null, true),
            ]);
            self::assertSame(201, $response->status(), $response->getContent());
            $response->assertJsonPath('mimeType', 'image/svg+xml')->assertJsonPath('thumbnailUrl', null);
            ftruncate($file, 0);
            rewind($file);
            fwrite($file, '<html><script>alert(1)</script></html>');
            $this->withHeader('Idempotency-Key', 'test-svg-0000002')->postJson($this->url($workspace, 'uploads'), [
                'file' => new UploadedFile($path, 'wrong.svg', null, null, true),
            ])->assertUnprocessable();
        } finally {
            fclose($file);
        }
        $this->withHeader('Idempotency-Key', 'test-png-0000001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('blocked.png'),
        ])->assertUnprocessable();

        config()->set('media-studio.upload.sessions.enabled', true);
        $this->getJson($this->url($workspace, 'upload-policy'))->assertOk()->assertJsonPath('enabled', false);
        $this->patchJson($settings, ['revision' => 1, 'extensions' => ['svg'], 'chunks' => true])->assertOk();
        $this->getJson($this->url($workspace, 'upload-policy'))->assertOk()->assertJsonPath('enabled', true);
        $this->postJson($this->url($workspace, 'upload-sessions'), ['name' => 'blocked.wav', 'size' => 1000, 'key' => 'test-chunk-000001'])->assertUnprocessable();
    }

    public function test_chunk_limit_is_scoped_to_new_sessions_and_cannot_exceed_server_limits(): void
    {
        config()->set('media-studio.upload.sessions.enabled', true);
        config()->set('media-studio.upload.sessions.max_bytes', 4194304);
        $workspace = $this->workspace();
        $settings = $this->url($workspace, 'settings');
        $sessions = $this->url($workspace, 'upload-sessions');
        $upload = ['name' => 'large.svg', 'size' => 2097152, 'key' => 'resumable-session-0001'];
        $this->postJson($sessions, $upload)->assertCreated();
        $values = ['revision' => 0, 'extensions' => ['svg'], 'chunks' => true];
        $this->patchJson($settings, [...$values, 'chunkMaxMiB' => 5])->assertUnprocessable();
        $this->patchJson($settings, [...$values, 'chunkMaxMiB' => 1])->assertOk()
            ->assertJsonPath('chunkMaxMiB', 1)->assertJsonPath('transferPolicy.serverMaxMiB', 4);
        $this->getJson($this->url($workspace, 'upload-policy'))->assertOk()->assertJsonPath('maxBytes', 1048576);
        $this->postJson($sessions, $upload)->assertCreated();
        $this->postJson($sessions, [...$upload, 'key' => 'resumable-session-0002'])->assertUnprocessable();
        $this->patchJson($settings, [...$values, 'revision' => 1, 'chunkMaxMiB' => null])->assertOk()->assertJsonPath('chunkMaxMiB', null);
        $this->postJson($sessions, [...$upload, 'key' => 'resumable-session-0002'])->assertCreated();
    }

    public function test_settings_gate_requires_workspace_membership_and_write_role(): void
    {
        $workspace = $this->workspace();
        $id = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $settings = $this->url($workspace, 'settings');
        config()->set('media-studio.settings.ability', SettingsController::MANAGE);
        Gate::define(SettingsController::MANAGE, static fn (GenericUser $user): bool => $user->id !== 'member-uuid-001');
        $data = ['revision' => 0, 'extensions' => ['jpg'], 'chunks' => false];
        $this->patchJson($settings, $data)->assertForbidden();
        foreach (['editor' => 200, 'viewer' => 403, 'outsider' => 404] as $role => $status) {
            $principal = new Principal('user', $role);
            if ($role !== 'outsider') {
                DB::table('fms_memberships')->insert([
                    'workspace_id' => $id, 'principal_key' => $principal->key(), 'principal_type' => 'user',
                    'principal_id' => $role, 'role' => $role,
                ]);
            }
            $this->actingAs(new GenericUser(['id' => $role]))->patchJson($settings, $data)->assertStatus($status);
        }
    }
}
