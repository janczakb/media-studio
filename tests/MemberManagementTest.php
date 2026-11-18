<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Members\MemberDirectoryScope;

final class MemberManagementTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('member_users', function (Blueprint $table): void {
            $table->id();
            $table->string('name');
            $table->string('email');
            $table->timestamp('email_verified_at')->nullable();
        });
        config()->set('media-studio.principals', ['user' => MemberManagementUser::class]);
        config()->set('media-studio.members.directory', MemberManagementDirectory::class);
        $this->actingAs(MemberManagementUser::query()->create(['name' => 'Owner', 'email' => 'owner@example.test', 'email_verified_at' => now()]));
    }

    public function test_member_search_paginates_matches_across_a_thousand_members_and_excludes_nonmembers(): void
    {
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $profiles = [];
        for ($i = 0; $i < 1000; $i++) {
            $profiles[] = ['name' => ($i >= 970 ? 'Designer ' : 'Other ').$i, 'email' => 'member'.$i.'@example.test'];
        }
        foreach (array_chunk($profiles, 100) as $batch) {
            DB::table('member_users')->insert($batch);
        }
        $members = DB::table('member_users')->where('id', '>', 1)->orderBy('id')->pluck('id')->map(function ($id) use ($workspaceId) {
            $principal = new Principal('user', (string) $id);

            return ['workspace_id' => $workspaceId, 'principal_type' => 'user', 'principal_id' => (string) $id, 'principal_key' => $principal->key(), 'role' => 'viewer'];
        });
        foreach ($members->chunk(100) as $batch) {
            DB::table('fms_memberships')->insert($batch->all());
        }
        MemberManagementUser::query()->create(['name' => 'Designer outsider', 'email' => 'outside@example.test']);
        $url = $this->url($workspace, 'members');
        $first = $this->getJson($url.'?search=DESIGNER')->assertOk()->assertJsonCount(25, 'items')
            ->assertJsonPath('items.0.name', 'Designer 970')->assertJsonPath('hasMore', true);
        $cursor = urlencode($first->json('nextCursor'));
        $this->getJson($url.'?search=DESIGNER&cursor='.$cursor)->assertOk()->assertJsonCount(5, 'items')
            ->assertJsonPath('items.4.name', 'Designer 999')->assertJsonPath('hasMore', false);
        $this->getJson($url.'?search=Other&cursor='.$cursor)->assertUnprocessable();
        $this->getJson($url.'?search=Designer%25')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_creation_directory_requires_three_characters_caps_results_and_adds_without_mail(): void
    {
        Mail::fake();
        $workspace = $this->workspace();
        for ($i = 0; $i < 22; $i++) {
            MemberManagementUser::query()->create(['name' => 'Teammate '.$i, 'email' => 'team'.$i.'@example.test']);
        }
        $this->getJson($this->url($workspace, 'workspace-creation-candidates?search=Te'))->assertUnprocessable();
        $response = $this->getJson($this->url($workspace, 'workspace-creation-candidates?search=Team'))->assertOk()->assertJsonCount(15, 'items')->assertHeader('Cache-Control', 'no-store, private');
        $user = $response->json('items.0');
        $team = $this->workspace('New team');
        $this->postJson($this->url($team, 'members'), ['type' => $user['type'], 'id' => $user['id'], 'role' => 'editor'])->assertCreated();
        $this->postJson($this->url($team, 'members'), ['type' => $user['type'], 'id' => $user['id'], 'role' => 'editor'])->assertCreated();
        self::assertSame(2, DB::table('fms_memberships')->where('workspace_id', DB::table('fms_workspaces')->where('public_id', $team)->value('id'))->count());
        Mail::assertNothingSent();
    }

    public function test_scoped_candidate_can_be_added_changed_and_removed_without_global_role_mutations(): void
    {
        $workspace = $this->workspace();
        $user = MemberManagementUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->getJson($this->url($workspace, 'member-candidates?search=Ada'))->assertOk()->assertJsonPath('items.0.name', 'Ada');
        $this->postJson($this->url($workspace, 'members'), ['type' => 'user', 'id' => (string) $user->id, 'role' => 'viewer'])->assertCreated();
        $row = $this->getJson($this->url($workspace, 'members'))->assertOk()->json('items.1.id');
        $this->patchJson($this->url($workspace, 'members/'.$row), ['role' => 'editor'])->assertOk();
        $this->assertDatabaseHas('fms_memberships', ['id' => $row, 'role' => 'editor']);
        $this->patchJson($this->url($workspace, 'members/'.$row), ['role' => 'owner'])->assertUnprocessable();
        $this->postJson($this->url($workspace, 'members/'.$row.'/remove'))->assertOk();
        $this->assertDatabaseMissing('fms_memberships', ['id' => $row]);
    }

    public function test_admin_cannot_promote_peers_or_change_the_owner(): void
    {
        $workspace = $this->workspace();
        $owner = auth()->user();
        $admin = MemberManagementUser::query()->create(['name' => 'Admin', 'email' => 'admin@example.test']);
        $peer = MemberManagementUser::query()->create(['name' => 'Peer', 'email' => 'peer@example.test']);
        foreach ([$admin, $peer] as $person) {
            $this->postJson($this->url($workspace, 'members'), ['type' => 'user', 'id' => (string) $person->id, 'role' => 'admin'])->assertCreated();
        }
        $rows = $this->getJson($this->url($workspace, 'members'))->assertOk()->json('items');
        $this->actingAs($admin);
        $this->getJson($this->url($workspace))->assertOk();
        $this->patchJson($this->url($workspace, 'members/'.$rows[2]['id']), ['role' => 'viewer'])->assertForbidden();
        $this->postJson($this->url($workspace, 'members/'.$rows[2]['id'].'/remove'))->assertForbidden();
        $this->patchJson($this->url($workspace, 'members/'.$rows[0]['id']), ['role' => 'viewer'])->assertForbidden();
        $candidate = MemberManagementUser::query()->create(['name' => 'Candidate', 'email' => 'candidate@example.test']);
        $this->postJson($this->url($workspace, 'members'), ['type' => 'user', 'id' => (string) $candidate->id, 'role' => 'admin'])->assertForbidden();
        $this->postJson($this->url($workspace, 'members'), ['type' => 'user', 'id' => (string) $candidate->id, 'role' => 'editor'])->assertCreated();
        $this->actingAs($owner);
        $this->patchJson($this->url($workspace, 'members/'.$rows[2]['id']), ['role' => 'viewer'])->assertOk();
    }

    public function test_rotated_revoked_expired_and_wrong_recipient_invitations_are_rejected(): void
    {
        $tokens = [];
        Mail::shouldReceive('raw')->times(3)->andReturnUsing(function ($body) use (&$tokens): void {
            preg_match('/invitations\/([a-f0-9]{64})/', $body, $match);
            $tokens[] = $match[1];
        });
        $owner = auth()->user();
        $workspace = $this->workspace();
        $value = ['email' => 'ada@example.test', 'role' => 'viewer'];
        $id = $this->postJson($this->url($workspace, 'invitations'), $value)->assertCreated()->json('id');
        $this->postJson($this->url($workspace, 'invitations'), $value)->assertCreated()->assertJsonPath('id', $id);
        $recipient = MemberManagementUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test', 'email_verified_at' => now()]);
        $wrong = MemberManagementUser::query()->create(['name' => 'Wrong', 'email' => 'wrong@example.test', 'email_verified_at' => now()]);
        $this->actingAs($wrong)->postJson('/media-studio/api/v1/invitations/'.$tokens[1])->assertForbidden();
        $this->actingAs($recipient)->postJson('/media-studio/api/v1/invitations/'.$tokens[0])->assertNotFound();
        $this->actingAs($owner)->postJson($this->url($workspace, 'invitations/'.$id.'/revoke'))->assertOk();
        $this->actingAs($recipient)->postJson('/media-studio/api/v1/invitations/'.$tokens[1])->assertNotFound();
        $this->actingAs($owner)->postJson($this->url($workspace, 'invitations'), $value)->assertCreated();
        DB::table('fms_invitations')->where('id', $id)->update(['expires_at' => now()->subMinute()]);
        $this->actingAs($recipient)->postJson('/media-studio/api/v1/invitations/'.$tokens[2])->assertNotFound();
    }

    public function test_email_invitation_requires_verified_matching_identity_and_is_single_use(): void
    {
        Mail::fake();
        $workspace = $this->workspace();
        $token = null;
        Mail::shouldReceive('raw')->once()->andReturnUsing(function ($body, $callback) use (&$token): void {
            preg_match('/invitations\/([a-f0-9]{64})/', $body, $match);
            $token = $match[1];
        });
        $this->postJson($this->url($workspace, 'invitations'), ['email' => 'ada@example.test', 'role' => 'viewer'])->assertCreated();
        $user = MemberManagementUser::query()->create(['name' => 'Ada', 'email' => 'ada@example.test']);
        $this->actingAs($user);
        $this->postJson('/media-studio/api/v1/invitations/'.$token)->assertForbidden();
        $user->forceFill(['email_verified_at' => now()])->save();
        $this->actingAs($user->fresh());
        $this->postJson('/media-studio/api/v1/invitations/'.$token)->assertOk()->assertJsonPath('accepted', true);
        $this->postJson('/media-studio/api/v1/invitations/'.$token)->assertNotFound();
        $this->getJson($this->url($workspace))->assertOk();
    }
}

final class MemberManagementUser extends User implements MustVerifyEmail
{
    use \Illuminate\Auth\MustVerifyEmail;

    protected $table = 'member_users';

    public $timestamps = false;

    protected $guarded = [];

    protected function casts(): array
    {
        return ['email_verified_at' => 'datetime'];
    }
}

final class MemberManagementDirectory implements MemberDirectoryScope
{
    public function query(Authenticatable $actor, object $workspace): Builder
    {
        return MemberManagementUser::query()->where('id', '!=', $actor->getAuthIdentifier());
    }
}
