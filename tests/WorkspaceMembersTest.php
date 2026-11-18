<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Identity\Principal;

final class WorkspaceMembersTest extends TestCase
{
    public function test_view_and_manage_abilities_are_independent_and_enforced(): void
    {
        $workspace = $this->workspace();
        Gate::define('studio.members.view', fn () => true);
        Gate::define('studio.members.manage', fn () => false);
        $ownerId = $this->getJson($this->url($workspace, 'members'))->assertOk()->assertJsonPath('items.0.canRemove', false)->json('items.0.id');
        $this->postJson($this->url($workspace, 'members/'.$ownerId.'/remove'))->assertForbidden();
        Gate::define('studio.members.view', fn () => false);
        $this->getJson($this->url($workspace, 'members'))->assertForbidden();
    }

    public function test_current_user_profile_and_configurable_fields_are_returned(): void
    {
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001', 'display_name' => 'Ada', 'contact' => 'ada@example.test', 'email' => 'hidden@example.test', 'picture' => '/avatars/ada.png']));
        config()->set('media-studio.member_profiles.fields', ['name' => 'display_name', 'email' => 'contact', 'avatar_url' => 'picture']);
        $workspace = $this->workspace();
        $this->getJson($this->url($workspace, 'members'))->assertOk()
            ->assertJsonPath('items.0.name', 'Ada')
            ->assertJsonPath('items.0.email', 'ada@example.test')
            ->assertJsonPath('items.0.avatarUrl', '/avatars/ada.png');
        config()->set('media-studio.member_profiles.fields.email', null);
        $this->getJson($this->url($workspace, 'members'))->assertOk()->assertJsonPath('items.0.email', null);
    }

    public function test_members_are_paginated_scoped_and_only_owner_can_remove_nonowners(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        $id = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        for ($i = 0; $i < 30; $i++) {
            $principal = new Principal('user', 'member-'.$i);
            DB::table('fms_memberships')->insert(['workspace_id' => $id, 'principal_type' => $principal->type, 'principal_id' => $principal->id, 'principal_key' => $principal->key(), 'role' => 'viewer']);
        }
        $first = $this->getJson($this->url($workspace, 'members'))->assertOk()->assertJsonCount(25, 'items')->json();
        self::assertTrue($first['hasMore']);
        $this->getJson($this->url($workspace, 'members?cursor='.urlencode($first['nextCursor'])))->assertOk()->assertJsonCount(6, 'items')->assertJsonPath('hasMore', false);
        $this->getJson($this->url($other, 'members?cursor='.urlencode($first['nextCursor'])))->assertStatus(422);
        $this->postJson($this->url($workspace, 'members/'.$first['items'][0]['id'].'/remove'))->assertForbidden();
        $target = $first['items'][1]['id'];
        Gate::define('studio.members.manage', fn ($user) => $user->getAuthIdentifier() === 'member-uuid-001');
        $this->actingAs(new GenericUser(['id' => 'member-0']));
        $this->postJson($this->url($workspace, 'members/'.$target.'/remove'))->assertForbidden();
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001']));
        $this->postJson($this->url($workspace, 'members/'.$target.'/remove'))->assertOk();
        $this->postJson($this->url($workspace, 'members/'.$target.'/remove'))->assertNotFound();
        $this->actingAs(new GenericUser(['id' => 'stranger']));
        $this->getJson($this->url($workspace, 'members'))->assertNotFound();
    }
}
