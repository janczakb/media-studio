<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Auth\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Identity\Principal;

final class CommentsTest extends TestCase
{
    private function file(string $w): string
    {
        return $this->postJson($this->url($w, 'notes'), ['title' => 'Brief', 'background' => '#ffffff', 'key' => 'comments-note-fixture', 'document' => ['type' => 'doc', 'content' => [['type' => 'paragraph', 'content' => [['type' => 'text', 'text' => 'Brief']]]]]])->assertCreated()->json('entry.id');
    }

    public function test_image_comment_anchors_are_version_scoped_idempotent_and_validated(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $version = (string) Str::ulid();
        DB::table('fms_entries')->where('public_id', $e)->update(['media_type' => 'image', 'current_version_id' => $version]);
        $url = $this->url($w, "entries/$e/comments");
        $input = ['body' => 'Check this area', 'key' => 'image-comment-anchor-001', 'anchor' => ['versionId' => $version, 'x' => 375, 'y' => 620]];
        $comment = $this->postJson($url, $input)->assertCreated()->assertJsonPath('anchor.x', 375)->assertJsonPath('anchor.versionId', $version)->json();
        $this->postJson($url, $input)->assertCreated()->assertJsonPath('id', $comment['id']);
        $changed = $input;
        $changed['anchor']['x'] = 400;
        $this->postJson($url, $changed)->assertConflict();
        $this->postJson($url, ['body' => 'General comment', 'key' => 'unanchored-comment-001'])->assertCreated();
        $this->getJson($url.'?anchorVersion='.$version)->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($url.'?anchorVersion='.(string) Str::ulid())->assertOk()->assertJsonCount(0, 'items');
        $invalid = $input;
        $invalid['key'] = 'invalid-anchor-new-001';
        $invalid['anchor']['x'] = 1001;
        $this->postJson($url, $invalid)->assertUnprocessable();
        $invalid['anchor']['x'] = 250;
        $invalid['anchor']['versionId'] = (string) Str::ulid();
        $this->postJson($url, $invalid)->assertUnprocessable();
        $this->patchJson($url.'/'.$comment['id'], ['revision' => 1, 'resolved' => true])->assertOk();
        $this->getJson($url.'?filter=open&anchorVersion='.$version)->assertOk()->assertJsonCount(0, 'items');
        $other = $this->workspace('Other');
        $this->getJson($this->url($other, "entries/$e/comments").'?anchorVersion='.$version)->assertNotFound();
        self::assertSame(2, DB::table('fms_comments')->count());
    }

    public function test_comments_persist_idempotently_resolve_edit_and_delete_with_revision_check(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $url = $this->url($w, "entries/$e/comments");
        $data = ['body' => 'A useful comment <script>alert(1)</script>', 'mentions' => [], 'key' => 'comment-request-key-001'];
        $c = $this->postJson($url, $data)->assertCreated()->json();
        $this->postJson($url, $data)->assertCreated()->assertJsonPath('id', $c['id']);
        self::assertSame(1, DB::table('fms_comments')->count());
        $this->getJson($url)->assertOk()->assertJsonPath('items.0.body', $data['body']);
        $path = $url.'/'.$c['id'];
        $this->patchJson($path, ['revision' => 1, 'resolved' => true])->assertOk()->assertJsonPath('resolved', true);
        $this->patchJson($path, ['revision' => 1, 'body' => 'stale'])->assertConflict();
        $this->getJson($url.'?filter=open')->assertOk()->assertJsonCount(0, 'items');
        $this->patchJson($path, ['revision' => 2, 'body' => 'Edited', 'mentions' => []])->assertOk()->assertJsonPath('body', 'Edited');
        $this->patchJson($path, ['revision' => 3, 'delete' => true])->assertOk()->assertJsonPath('deleted', true);
        self::assertSame(0, DB::table('fms_comments')->count());
    }

    public function test_comment_access_and_mentions_are_scoped_and_feature_can_be_disabled(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $url = $this->url($w, "entries/$e/comments");
        $this->postJson($url, ['body' => 'Hello', 'mentions' => ['999999'], 'key' => 'comment-request-key-002'])->assertUnprocessable();
        $other = $this->workspace('Other');
        $this->getJson($this->url($other, "entries/$e/comments"))->assertNotFound();
        Gate::define('studio.comments.create', fn () => false);
        $this->postJson($url, ['body' => 'Hello', 'key' => 'comment-request-key-003'])->assertForbidden();
        config(['media-studio.comments.enabled' => false]);
        $this->getJson($url)->assertNotFound();
        self::assertSame(0, DB::table('fms_comments')->count());
    }

    public function test_mention_notifies_team_member_once_in_existing_inbox(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $wid = DB::table('fms_workspaces')->where('public_id', $w)->value('id');
        $p = new Principal('user', 'another-user');
        // Use the registered principal alias from the authenticated fixture.
        $type = DB::table('fms_memberships')->where('workspace_id', $wid)->value('principal_type');
        $p = new Principal($type, 'another-user');
        $id = DB::table('fms_memberships')->insertGetId(['workspace_id' => $wid, 'principal_type' => $type, 'principal_id' => $p->id, 'principal_key' => $p->key(), 'role' => 'viewer']);
        $data = ['body' => '@Teammate please check', 'mentions' => [(string) $id], 'key' => 'comment-mention-key-001'];
        $url = $this->url($w, "entries/$e/comments");
        $this->postJson($url, $data)->assertCreated();
        $this->postJson($url, $data)->assertCreated();
        self::assertSame(1, DB::table('fms_notifications')->where('principal_key', $p->key())->count());
        self::assertSame('mentioned', DB::table('fms_activity')->value('event'));
    }

    public function test_other_member_cannot_edit_or_delete_an_authors_comment_without_moderation(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $url = $this->url($w, "entries/$e/comments");
        $c = $this->postJson($url, ['body' => 'Original', 'key' => 'comment-authorship-key'])->assertCreated()->json();
        $p = new Principal('user', 'reviewer');
        $wid = DB::table('fms_workspaces')->where('public_id', $w)->value('id');
        DB::table('fms_memberships')->insert(['workspace_id' => $wid, 'principal_type' => $p->type, 'principal_id' => $p->id, 'principal_key' => $p->key(), 'role' => 'viewer']);
        $this->actingAs(new GenericUser(['id' => $p->id]));
        Gate::define('studio.comments.manage', fn () => false);
        $path = $url.'/'.$c['id'];
        $this->patchJson($path, ['revision' => 1, 'body' => 'Unauthorized'])->assertForbidden();
        $this->patchJson($path, ['revision' => 1, 'delete' => true])->assertForbidden();
        $this->patchJson($path, ['revision' => 1, 'resolved' => true])->assertOk();
        self::assertSame('Original', DB::table('fms_comments')->value('body'));
    }

    public function test_cursor_is_bound_to_file_and_filter_and_page_is_bounded(): void
    {
        $w = $this->workspace();
        $e = $this->file($w);
        $url = $this->url($w, "entries/$e/comments");
        for ($i = 0; $i < 32; $i++) {
            if ($i === 25) {
                $this->travel(61)->seconds();
            }$this->postJson($url, ['body' => 'Comment '.$i, 'key' => 'comment-pagination-'.$i])->assertCreated();
        }
        $first = $this->getJson($url)->assertOk()->assertJsonCount(30, 'items')->json();
        $this->getJson($url.'?cursor='.urlencode($first['nextCursor']))->assertOk()->assertJsonCount(2, 'items');
        $this->getJson($url.'?filter=open&cursor='.urlencode($first['nextCursor']))->assertUnprocessable();
    }

    public function test_structured_mentions_store_principal_identity_and_display_current_profile_name(): void
    {
        Schema::create('comment_people', function (Blueprint $t) {
            $t->string('id')->primary();
            $t->string('name');
        });
        config(['media-studio.principals.person' => CommentPerson::class]);
        CommentPerson::query()->create(['id' => 'person-uuid-1', 'name' => 'Original name']);
        $w = $this->workspace();
        $e = $this->file($w);
        $wid = DB::table('fms_workspaces')->where('public_id', $w)->value('id');
        $p = new Principal('person', 'person-uuid-1');
        DB::table('fms_memberships')->insert(['workspace_id' => $wid, 'principal_type' => $p->type, 'principal_id' => $p->id, 'principal_key' => $p->key(), 'role' => 'viewer']);
        $url = $this->url($w, "entries/$e/comments");
        $d = ['body' => 'Please review @Original name', 'key' => 'structured-mention-001', 'document' => [['type' => 'text', 'text' => 'Please review '], ['type' => 'mention', 'principalType' => 'person', 'id' => 'person-uuid-1', 'name' => 'Spoofed label', 'style' => 'unsafe']]];
        $d['document'] = json_encode($d['document']);
        $this->postJson($url, $d)->assertCreated()->assertJsonPath('document.1.name', 'Original name');
        $stored = json_decode(DB::table('fms_comments')->value('document'), true);
        self::assertSame(['type' => 'mention', 'principalType' => 'person', 'id' => 'person-uuid-1'], $stored[1]);
        self::assertStringNotContainsString('Original name', DB::table('fms_comments')->value('body'));
        CommentPerson::query()->whereKey('person-uuid-1')->update(['name' => 'Current name']);
        $this->getJson($url)->assertOk()->assertJsonPath('items.0.document.1.name', 'Current name')->assertJsonPath('items.0.body', 'Please review @Current name');
        $this->getJson($this->url($w, "entries/$e/comment-mentions?search=Cur"))->assertOk()->assertJsonPath('items.0.id', 'person-uuid-1')->assertJsonPath('items.0.principalType', 'person');
        $this->postJson($url, $d)->assertCreated();
        self::assertSame(1, DB::table('fms_notifications')->where('principal_key', $p->key())->count());
    }
}

final class CommentPerson extends User
{
    protected $table = 'comment_people';

    public $timestamps = false;

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];
}
