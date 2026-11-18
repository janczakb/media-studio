<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Activity\ActivityLog;
use UseFlex\MediaStudio\Identity\Principal;

final class StudioExperienceTest extends TestCase
{
    public function test_directory_exposes_configured_primary_color_and_only_the_current_profile(): void
    {
        config(['media-studio.appearance.primary_color' => '#b51b43']);
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001', 'name' => 'Actual viewer', 'email' => 'actual@example.test']));
        $this->workspace('Brand');
        $user = auth()->user();
        $this->getJson('/media-studio/api/v1/workspaces')->assertOk()
            ->assertJsonPath('appearance.primaryColor', '#b51b43')
            ->assertJsonPath('viewer.name', $user->name)
            ->assertJsonPath('viewer.email', $user->email);
    }

    public function test_workspace_limit_and_single_default_mode_are_enforced_by_the_server(): void
    {
        config(['media-studio.workspaces.max_owned' => 2]);
        $first = $this->workspace('First');
        $second = $this->workspace('Second');
        $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Third'])->assertUnprocessable();
        $this->getJson('/media-studio/api/v1/workspaces')->assertJsonPath('canCreate', false)->assertJsonPath('workspaceLimit', 2);
        config(['media-studio.workspaces.enabled' => false, 'media-studio.default_workspace.enabled' => true]);
        $default = $this->getJson('/media-studio/api/v1/workspaces')->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('workspacesEnabled', false)->assertJsonPath('canCreate', false)->json('items.0.id');
        $this->getJson($this->url($first))->assertNotFound();
        $this->postJson('/media-studio/api/v1/workspaces', ['name' => 'Not allowed'])->assertForbidden();
        $this->postJson($this->url($default, 'folders'), ['name' => 'Allowed'])->assertCreated();
    }

    public function test_workspace_deletion_requires_owner_exact_name_and_empty_nondefault_workspace(): void
    {
        $w = $this->workspace('Disposable');
        $this->deleteJson($this->url($w, ''), ['confirmName' => 'Wrong'])->assertUnprocessable();
        $this->deleteJson($this->url($w, ''), ['confirmName' => 'Disposable'])->assertOk();
        $this->assertFalse(DB::table('fms_workspaces')->where('public_id', $w)->exists());
        $w = $this->workspace('With files');
        $this->postJson($this->url($w, 'folders'), ['name' => 'Keep'])->assertCreated();
        $this->deleteJson($this->url($w, ''), ['confirmName' => 'With files'])->assertConflict();
        $this->assertTrue(DB::table('fms_workspaces')->where('public_id', $w)->exists());
    }

    public function test_notifications_have_actor_and_cached_capped_count_with_scoped_batch_read(): void
    {
        $this->actingAs(new GenericUser(['id' => 'member-uuid-001', 'name' => 'Actual author']));
        $w = $this->workspace();
        $entry = $this->postJson($this->url($w, 'folders'), ['name' => 'Notification target'])->assertCreated()->json('id');
        $scope = DB::table('fms_workspaces')->where('public_id', $w)->first();
        $row = DB::table('fms_entries')->where('public_id', $entry)->first();
        for ($i = 0; $i < 14; $i++) {
            app(ActivityLog::class)->record($scope, Principal::fromUser(auth()->user()), $row, 'mentioned', 'notification-'.$i);
        }
        $path = $this->url($w, 'notifications');
        $page = $this->getJson($path)->assertOk()->assertJsonPath('unreadCount', 10)->assertJsonPath('items.0.actor.name', 'Actual author')->json();
        $this->getJson($path)->assertJsonPath('unreadCount', 10);
        $this->postJson($path.'/read-visible', ['ids' => array_column($page['items'], 'id')])->assertOk()->assertJsonPath('unreadCount', 0);
        $this->getJson($path.'?unread=1')->assertJsonCount(0, 'items')->assertJsonPath('unreadCount', 0);
        app(ActivityLog::class)->record($scope, Principal::fromUser(auth()->user()), $row, 'mentioned', 'new-notification');
        $this->getJson($path)->assertJsonPath('unreadCount', 1);
        Gate::define('studio.notifications.manage', fn () => false);
        $this->postJson($path.'/read-visible', ['ids' => [$page['items'][0]['id']]])->assertForbidden();
    }
}
