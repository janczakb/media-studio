<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests\Feature\BrowserExtension;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use UseFlex\MediaStudio\Library\EntryTags;
use UseFlex\MediaStudio\Tests\TestCase;

final class ConnectionTest extends TestCase
{
    private const API = '/media-studio/extension/v1';

    private const VERIFIER = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    protected function setUp(): void
    {
        parent::setUp();
        (require __DIR__.'/../../../packages/laravel/database/extension-migrations/2026_11_10_000041_browser_extension.php')->up();
        config(['media-studio.integrations.browser_extension.enabled' => true, 'media-studio.integrations.browser_extension.studio_url' => 'http://localhost']);
    }

    private function grant(string $workspace): array
    {
        $request = $this->postJson(self::API.'/devices', ['challenge' => hash('sha256', self::VERIFIER), 'name' => 'Chrome'])->assertOk()->json();
        $this->postJson($this->url($workspace, 'integrations/browser-extension/approve'), ['id' => $request['id'], 'code' => $request['code']])->assertOk();

        return $this->postJson(self::API.'/devices/claim', ['id' => $request['id'], 'verifier' => self::VERIFIER])->assertOk()->json();
    }

    public function test_explicit_approval_and_verifier_are_required_and_claim_is_one_use(): void
    {
        $w = $this->workspace();
        $r = $this->postJson(self::API.'/devices', ['challenge' => hash('sha256', self::VERIFIER), 'name' => 'Firefox'])->assertOk()->json();
        $this->postJson(self::API.'/devices/claim', ['id' => $r['id'], 'verifier' => str_repeat('b', 64)])->assertUnauthorized();
        $this->postJson(self::API.'/devices/claim', ['id' => $r['id'], 'verifier' => self::VERIFIER])->assertStatus(202);
        $this->postJson($this->url($w, 'integrations/browser-extension/approve'), ['id' => $r['id'], 'code' => '00000000'])->assertConflict();
        $this->postJson($this->url($w, 'integrations/browser-extension/approve'), ['id' => $r['id'], 'code' => $r['code']])->assertOk();
        $this->travel(6)->seconds();
        $g = $this->postJson(self::API.'/devices/claim', ['id' => $r['id'], 'verifier' => self::VERIFIER])->assertOk()->json();
        self::assertStringStartsWith('fms_ext_', $g['token']);
        self::assertSame($w, $g['workspace']['id']);
        self::assertStringNotContainsString(substr($g['token'], -64), json_encode(DB::table('fms_extension_tokens')->first()));
        $this->postJson(self::API.'/devices/claim', ['id' => $r['id'], 'verifier' => self::VERIFIER])->assertUnauthorized();
    }

    public function test_upload_goes_to_workspace_disk_with_idempotency_and_cannot_delete_or_cross_workspaces(): void
    {
        $w = $this->workspace();
        $other = $this->workspace('Another');
        $g = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$g['token']];
        $this->getJson(self::API.'/connection', $headers)->assertOk()->assertJsonPath('destination.disk', 'studio-test');
        $this->postJson(self::API.'/workspaces/'.$other.'/uploads', [], $headers)->assertNotFound();
        $payload = ['key' => 'capture-key-123456789', 'file' => UploadedFile::fake()->create('capture.txt', 1, 'text/plain')];
        $r = $this->post(self::API.'/workspaces/'.$w.'/uploads', $payload, $headers + ['Accept' => 'application/json'])->assertCreated();
        $id = $r->json('id');
        self::assertNotNull($id);
        $this->post(self::API.'/workspaces/'.$w.'/uploads', $payload, $headers + ['Accept' => 'application/json'])->assertCreated()->assertJsonPath('id', $id);
        $this->postJson(self::API.'/workspaces/'.$w.'/entries/'.$id.'/trash', [], $headers)->assertNotFound();
        $this->getJson(self::API.'/workspaces/'.$w.'/entries/'.$id.'/content', $headers)->assertNotFound();
        $this->deleteJson(self::API.'/connection', [], $headers)->assertOk();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
    }

    public function test_permission_revocation_and_membership_removal_take_effect_without_token_expiry(): void
    {
        $w = $this->workspace();
        $g = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$g['token']];
        Gate::define('studio.extension.use', static fn () => false);
        $this->getJson(self::API.'/connection', $headers)->assertForbidden();
        Gate::define('studio.extension.use', static fn () => true);
        DB::table('fms_memberships')->delete();
        $this->getJson(self::API.'/connection', $headers)->assertNotFound();
    }

    public function test_connection_reports_the_current_workspace_feature_setting(): void
    {
        $w = $this->workspace();
        $g = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$g['token']];

        config(['media-studio.workspaces.enabled' => true]);
        $this->getJson(self::API.'/connection', $headers)->assertOk()->assertJsonPath('capabilities.workspaces', true);
        $this->postJson($this->url($w, 'make-default'))->assertOk();
        config(['media-studio.workspaces.enabled' => false]);
        $this->getJson(self::API.'/connection', $headers)->assertOk()->assertJsonPath('capabilities.workspaces', false);
    }

    public function test_folder_browser_pages_in_both_directions_and_loads_only_the_requested_parent(): void
    {
        $w = $this->workspace();
        $g = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$g['token']];
        $ids = [];
        for ($i = 0; $i < 65; $i++) {
            $ids[] = $this->postJson($this->url($w, 'folders'), ['name' => sprintf('Folder %02d', $i)])->assertCreated()->json('id');
        }
        $child = $this->postJson($this->url($w, 'folders'), ['name' => 'Nested', 'parent' => $ids[0]])->assertCreated()->json('id');
        $endpoint = self::API.'/workspaces/'.$w.'/folders';
        $first = $this->getJson($endpoint, $headers)->assertOk()->assertJsonCount(60, 'items')->json();
        self::assertNull($first['previousCursor']);
        $second = $this->getJson($endpoint.'?after='.$first['nextCursor'], $headers)->assertOk()->assertJsonCount(5, 'items')->json();
        self::assertNull($second['nextCursor']);
        self::assertEmpty(array_intersect(array_column($first['items'], 'id'), array_column($second['items'], 'id')));
        $previous = $this->getJson($endpoint.'?before='.$second['previousCursor'], $headers)->assertOk()->json();
        self::assertSame($first['items'], $previous['items']);
        self::assertNull($previous['previousCursor']);
        $this->getJson($endpoint.'?parent='.$ids[0], $headers)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $child);
        $this->getJson($endpoint.'?search=Folder%206', $headers)->assertOk()->assertJsonCount(5, 'items');
        $this->getJson($endpoint.'?after='.$ids[0].'&before='.$ids[1], $headers)->assertUnprocessable();
        $other = $this->workspace('Other library');
        $this->getJson(self::API.'/workspaces/'.$other.'/folders', $headers)->assertNotFound();
    }

    public function test_capture_tag_suggestions_are_scoped_and_existing_tag_identity_is_preserved(): void
    {
        $w = $this->workspace();
        $other = $this->workspace('Private library');
        $folder = $this->postJson($this->url($w, 'folders'), ['name' => 'References'])->assertCreated()->json('id');
        $workspace = DB::table('fms_workspaces')->where('public_id', $w)->first();
        $folderId = DB::table('fms_entries')->where('public_id', $folder)->value('id');
        $tags = app(EntryTags::class);
        $tags->project($workspace, $folderId, array_map(fn ($i) => ['name' => sprintf('Nature %02d', $i)], range(0, 19)));
        $tag = $tags->forEntry($folderId)[0];
        $grant = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$grant['token']];
        $this->getJson(self::API.'/workspaces/'.$w.'/tags?search=Nature', $headers)->assertOk()->assertJsonCount(15, 'items')->assertJsonPath('hasMore', true);
        $this->getJson(self::API.'/workspaces/'.$other.'/tags?search=Nature', $headers)->assertNotFound();
        $this->getJson(self::API.'/workspaces/'.$w.'/tags?search='.str_repeat('a', 101), $headers)->assertUnprocessable();
        $key = 'tag-capture-123456789';
        $upload = $this->post(self::API.'/workspaces/'.$w.'/uploads', ['key' => $key, 'file' => UploadedFile::fake()->create('tagged.txt', 1, 'text/plain')], $headers + ['Accept' => 'application/json'])->assertCreated()->json('id');
        $this->postJson(self::API.'/workspaces/'.$w.'/captures/'.$upload, ['key' => $key, 'sourceUrl' => 'https://example.test/gallery', 'tags' => [], 'tagRefs' => [['id' => $tag['id']]]], $headers)->assertOk();
        $entryId = DB::table('fms_entries')->where('public_id', $upload)->value('id');
        self::assertSame($tag['id'], $tags->forEntry($entryId)[0]['id']);
        self::assertSame(20, DB::table('fms_tags')->where('workspace_id', $workspace->id)->count());
    }

    public function test_disabled_and_expired_connections_fail_closed(): void
    {
        $w = $this->workspace();
        $g = $this->grant($w);
        $headers = ['Authorization' => 'Bearer '.$g['token']];
        $this->travel(31)->days();
        $this->getJson(self::API.'/connection', $headers)->assertUnauthorized();
        config(['media-studio.integrations.browser_extension.enabled' => false]);
        $this->postJson(self::API.'/devices', ['challenge' => hash('sha256', self::VERIFIER), 'name' => 'Chrome'])->assertNotFound();
    }
}
