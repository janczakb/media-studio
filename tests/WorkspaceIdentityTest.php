<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class WorkspaceIdentityTest extends TestCase
{
    public function test_identity_roundtrip_retry_and_personal_access_policy(): void
    {
        $body = ['name' => 'Design', 'emoji' => '👩🏽‍💻', 'kind' => 'personal', 'requestKey' => (string) Str::uuid()];
        $created = $this->postJson('/media-studio/api/v1/workspaces', $body)->assertCreated()->assertJsonPath('emoji', '👩🏽‍💻')->assertJsonPath('workspaceType', 'personal')->json();
        self::assertFalse($created['permissions']['members.invite']);
        $this->postJson('/media-studio/api/v1/workspaces', $body)->assertCreated()->assertJsonPath('id', $created['id']);
        self::assertSame(1, DB::table('fms_workspaces')->where('public_id', $created['id'])->count());
        $this->postJson('/media-studio/api/v1/workspaces', [...$body, 'emoji' => '🚀'])->assertConflict();
        $this->getJson($this->url($created['id'], ''))->assertOk()->assertJsonPath('emoji', '👩🏽‍💻');
        $this->postJson($this->url($created['id'], 'make-default'))->assertUnprocessable();
        $this->postJson($this->url($created['id'], 'invitations'), ['email' => 'person@example.com', 'role' => 'editor'])->assertForbidden();
        $this->patchJson($this->url($created['id'], ''), ['name' => 'Design updated', 'emoji' => '🚀'])->assertOk()->assertJsonPath('emoji', '🚀');
        $this->patchJson($this->url($created['id'], ''), ['name' => 'Design updated'])->assertOk()->assertJsonPath('emoji', '🚀');
        $this->patchJson($this->url($created['id'], ''), ['name' => 'Design updated', 'emoji' => null])->assertOk()->assertJsonPath('emoji', null);
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonPath('workspaceIdentity', true)->assertJsonPath('items.0.emoji', null);
    }

    public function test_emoji_validation_and_legacy_creation_are_compatible(): void
    {
        foreach (['<img src=x>', '🚀🚀', 'abc', ''] as $emoji) {
            // Laravel converts empty strings to null, which deliberately resets the icon.
            if ($emoji === '') {
                continue;
            }
            $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Invalid', 'emoji' => $emoji])->assertUnprocessable();
        }
        foreach (['🇵🇱', '1️⃣', '🏳️‍🌈'] as $emoji) {
            $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Emoji', 'emoji' => $emoji, 'kind' => 'team'])->assertCreated()->assertJsonPath('emoji', $emoji);
        }
        $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Existing SDK'])->assertCreated()->assertJsonPath('workspaceType', 'team')->assertJsonPath('emoji', null);
        $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Invalid', 'kind' => 'public'])->assertUnprocessable();
    }

    public function test_names_have_the_same_trimmed_limits_on_create_and_rename(): void
    {
        foreach (['ab', str_repeat('a', 16), '  ab  '] as $name) {
            $this->postJson('/media-studio/api/v1/workspaces', ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $created = $this->postJson('/media-studio/api/v1/workspaces', ['name' => '  abc  '])->assertCreated()->assertJsonPath('name', 'abc')->json();
        foreach (['ab', str_repeat('ą', 16), '  ab  '] as $name) {
            $this->patchJson($this->url($created['id'], ''), ['name' => $name])->assertUnprocessable()->assertJsonValidationErrors('name');
        }
        $this->patchJson($this->url($created['id'], ''), ['name' => str_repeat('ą', 15)])->assertOk()->assertJsonPath('name', str_repeat('ą', 15));
        $this->postJson('/media-studio/api/v1/workspaces', ['name' => str_repeat('a', 15)])->assertCreated();
    }
}
