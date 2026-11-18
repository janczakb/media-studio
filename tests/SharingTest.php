<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Integrations\Spatie\SourceIndexer;
use UseFlex\MediaStudio\Sharing\PrepareArchive;
use UseFlex\MediaStudio\Sharing\ShareEngagement;

final class SharingTest extends TestCase
{
    public function test_trashing_file_deletes_selection_links_and_invalidates_prepared_archive_without_losing_cleanup_receipt(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        config()->set('queue.default', 'sync');
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'Delete.png');
        $remaining = $this->file($workspace, 'Keep.png');
        $link = $this->share($workspace, [$file, $remaining], ['allowDownload' => true, 'allowArchive' => true]);
        $unrelated = $this->share($workspace, [$remaining]);
        $job = $this->postJson($link['url'].'/archive-jobs')->assertAccepted()->json('id');
        $this->getJson($link['url'].'/archive-jobs/'.$job)->assertOk()->assertJsonPath('state', 'ready');
        $key = DB::table('fms_archive_jobs')->where('id', $job)->value('object_key');
        $this->postJson($this->url($workspace, 'entries/'.$file.'/trash'), ['revision' => 1])->assertOk();
        $this->assertDatabaseMissing('fms_shares', ['public_id' => $link['id']]);
        $this->assertDatabaseHas('fms_shares', ['public_id' => $unrelated['id']]);
        $this->assertDatabaseHas('fms_archive_jobs', ['id' => $job, 'state' => 'failed', 'share_id' => null, 'object_key' => $key]);
        $this->getJson($link['url'].'/archive-jobs/'.$job.'/result')->assertNotFound();
        $this->getJson($this->url($workspace, 'archive-jobs/'.$job.'/result'))->assertNotFound();
        $this->postJson($this->url($workspace, 'entries/'.$file.'/restore'), ['revision' => 2])->assertOk();
        $this->getJson($link['url'])->assertNotFound();
        $this->getJson($unrelated['url'].'/entries')->assertOk();
    }

    public function test_bulk_trash_deletes_only_intersecting_links_and_legacy_links_are_removed_even_with_retention_disabled(): void
    {
        $workspace = $this->workspace();
        $first = $this->file($workspace, 'First.png');
        $second = $this->file($workspace, 'Second.png');
        $firstLink = $this->share($workspace, [$first]);
        $secondLink = $this->share($workspace, [$second]);
        $this->postJson($this->url($workspace, 'entries/bulk'), ['operation' => 'trash', 'items' => [['id' => $first, 'revision' => 1]]])->assertOk();
        $this->assertDatabaseMissing('fms_shares', ['public_id' => $firstLink['id']]);
        $this->assertDatabaseHas('fms_shares', ['public_id' => $secondLink['id']]);
        DB::table('fms_entries')->where('public_id', $second)->update(['trashed_at' => now()]);
        $this->getJson($secondLink['url'])->assertNotFound();
        $this->artisan('media-studio:trash:maintain')->assertSuccessful();
        $this->assertDatabaseMissing('fms_shares', ['public_id' => $secondLink['id']]);
        $this->assertDatabaseCount('fms_entries', 2);
    }

    public function test_unpublish_revokes_public_access_without_deleting_library_files(): void
    {
        $workspace = $this->workspace();
        $file = $this->folder($workspace, 'Public folder');
        $share = $this->share($workspace, [$file], ['visibility' => 'publish', 'allowIndexing' => true]);
        $path = $this->url($workspace, 'shares/'.$share['id']);
        $this->patchJson($path, ['revision' => 1, 'unpublish' => true])->assertOk()
            ->assertJsonPath('visibility', 'share')->assertJsonPath('allowIndexing', false);
        $this->getJson($share['url'])->assertNotFound();
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file, 'trashed_at' => null]);
        $this->patchJson($path, ['revision' => 2, 'unpublish' => true])->assertUnprocessable();
    }

    public function test_guest_upload_requires_enabled_managed_folder_and_live_share_permission(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Guest files');
        $other = $this->folder($workspace, 'Private');
        $this->postJson($this->url($workspace, 'shares'), ['title' => 'Disabled', 'entryIds' => [$folder], 'allowUpload' => true])->assertUnprocessable();
        config()->set('media-studio.sharing.guest_upload_enabled', true);
        $share = $this->share($workspace, [$folder], ['allowUpload' => true, 'password' => 'private-password']);
        $this->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('guest.png')])->assertStatus(423);
        $this->postJson($share['url'].'/unlock', ['password' => 'private-password'])->assertOk();
        $this->postJson($share['url'].'/uploads', ['parent' => $other, 'file' => UploadedFile::fake()->image('private.png')])->assertNotFound();
        $upload = UploadedFile::fake()->image('guest.png');
        $result = $this->withHeader('Idempotency-Key', 'guest-upload-key-00001')
            ->postJson($share['url'].'/uploads', ['file' => $upload])->assertCreated();
        $id = $result->json('id');
        $this->assertDatabaseHas('fms_entries', ['public_id' => $id, 'name' => 'guest.png']);
        $this->assertDatabaseHas('fms_activity', ['entry_public_id' => $id, 'event' => 'guest_uploaded', 'actor_type' => 'guest', 'actor_id' => $share['id']]);
        $this->postJson($share['url'].'/uploads', ['file' => $upload])->assertCreated()->assertJsonPath('id', $id);
        $items = $this->getJson($this->url($workspace, 'notifications'))->assertOk()->json('items');
        $notices = array_values(array_filter($items, fn ($item) => $item['entryId'] === $id && $item['event'] === 'guest_uploaded'));
        $this->assertCount(1, $notices);
        $this->assertDatabaseHas('fms_shares', ['public_id' => $share['id'], 'guest_upload_count' => 1]);
        config()->set('media-studio.sharing.guest_upload_max_files', 1);
        $this->withHeader('Idempotency-Key', 'guest-upload-key-00002')
            ->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('over-limit.png')])->assertUnprocessable();
        $this->patchJson($this->url($workspace, 'shares/'.$share['id']), ['revision' => 1, 'allowUpload' => false])->assertOk();
        $this->postJson($share['url'].'/unlock', ['password' => 'private-password'])->assertOk();
        $this->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('later.png')])->assertForbidden();
    }

    public function test_guest_upload_reauthorizes_the_share_owner_in_the_original_tenant(): void
    {
        config()->set('media-studio.sharing.guest_upload_enabled', true);
        $resolver = new class implements TenantResolver
        {
            public bool $allowed = true;

            public bool $active = true;

            public function current(Principal $principal): ?TenantIdentity
            {
                return $this->active ? new TenantIdentity('company', 'one') : null;
            }

            public function allows(Principal $principal, TenantIdentity $tenant): bool
            {
                return $this->allowed && $principal->id === 'member-uuid-001' && $tenant->id === 'one';
            }
        };
        $this->app->instance('test.guest.tenant', $resolver);
        config()->set('media-studio.tenancy.resolver', 'test.guest.tenant');
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'External');
        $share = $this->share($workspace, [$folder], ['allowUpload' => true]);
        $this->app['auth']->forgetGuards();
        $resolver->active = false;
        $this->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('guest-tenant.png')])->assertCreated();
        $resolver->allowed = false;
        $this->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('blocked.png')])->assertNotFound();
    }

    public function test_guest_background_archive_respects_password_share_and_revocation(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        config()->set('queue.default', 'sync');
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Photos');
        $file = $this->file($workspace, 'inside.png', $folder);
        $share = $this->share($workspace, [$folder], ['allowDownload' => true, 'allowArchive' => true, 'password' => 'private-password']);
        $other = $this->share($workspace, [$file], ['allowDownload' => true, 'allowArchive' => true]);
        $this->postJson($share['url'].'/archive-jobs')->assertStatus(423);
        $this->postJson($share['url'].'/unlock', ['password' => 'private-password'])->assertOk();
        $job = $this->postJson($share['url'].'/archive-jobs')->assertAccepted()->json('id');
        $this->getJson($share['url'].'/archive-jobs/'.$job)->assertOk()->assertJsonPath('state', 'ready');
        $this->get($share['url'].'/archive-jobs/'.$job.'/result')->assertDownload('shared-collection.zip');
        $this->getJson($other['url'].'/archive-jobs/'.$job)->assertNotFound();
        $this->getJson($this->url($workspace, 'archive-jobs/'.$job))->assertNotFound();
        $this->patchJson($this->url($workspace, 'shares/'.$share['id']), ['revision' => 1, 'revoked' => true])->assertOk();
        $this->getJson($share['url'].'/archive-jobs/'.$job.'/result')->assertNotFound();
    }

    public function test_queued_guest_archive_is_rejected_after_share_permission_changes(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        Queue::fake();
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'protected.png');
        $share = $this->share($workspace, [$file], ['allowDownload' => true, 'allowArchive' => true]);
        $id = $this->postJson($share['url'].'/archive-jobs')->assertAccepted()->json('id');
        $this->patchJson($this->url($workspace, 'shares/'.$share['id']), ['revision' => 1, 'allowArchive' => false])->assertOk();
        try {
            app()->call([new PrepareArchive($id), 'handle']);
            $this->fail('A revoked share must not generate an archive.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertDatabaseHas('fms_archive_jobs', ['id' => $id, 'state' => 'failed']);
        $this->getJson($share['url'].'/archive-jobs/'.$id.'/result')->assertForbidden();
    }

    public function test_authenticated_zip_contains_multiple_folders_and_selected_files(): void
    {
        $workspace = $this->workspace();
        $first = $this->folder($workspace, 'Photos');
        $second = $this->folder($workspace, 'Brand');
        $this->file($workspace, 'first.png', $first);
        $this->file($workspace, 'second.png', $second);
        $loose = $this->file($workspace, 'loose.png');
        $bytes = $this->get($this->url($workspace, 'download').'?'.http_build_query(['entries' => [$first, $second, $loose]]))
            ->assertOk()->assertDownload('media.zip')->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'fms-selection-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $this->assertNotFalse($zip->locateName('Photos/first.png'));
        $this->assertNotFalse($zip->locateName('Brand/second.png'));
        $this->assertNotFalse($zip->locateName('loose.png'));
        $zip->close();
        unlink($path);
    }

    public function test_interrupted_archive_is_recovered_without_republishing_old_attempt(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        config()->set('queue.default', 'sync');
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'recover.png');
        $id = $this->postJson($this->url($workspace, 'archive-jobs'), ['entries' => [$file]])->assertAccepted()->json('id');
        $old = DB::table('fms_archive_jobs')->where('id', $id)->first()->object_key;
        DB::table('fms_archive_jobs')->where('id', $id)->update(['state' => 'processing', 'lease_token' => str_repeat('a', 32), 'updated_at' => now()->subMinutes(71)]);
        $this->artisan('media-studio:recover-archives')->assertSuccessful();
        $new = DB::table('fms_archive_jobs')->where('id', $id)->first();
        $this->assertSame('ready', $new->state);
        $this->assertNotSame($old, $new->object_key);
        Storage::disk('studio-test')->assertMissing($old);
        Storage::disk('studio-test')->assertExists($new->object_key);
        $this->get($this->url($workspace, 'archive-jobs/'.$id.'/result'))->assertDownload('media.zip');
    }

    public function test_background_archive_is_owner_scoped_repeatable_and_invalidated_by_changes(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        config()->set('queue.default', 'sync');
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'archive.png');
        $job = $this->postJson($this->url($workspace, 'archive-jobs'), ['entries' => [$file]])->assertAccepted()->assertJsonPath('state', 'ready')->json('id');
        $url = $this->url($workspace, 'archive-jobs/'.$job);
        $this->getJson($url)->assertOk()->assertJsonPath('state', 'ready');
        $this->get($url.'/result')->assertDownload('media.zip');
        $this->get($url.'/result')->assertDownload('media.zip');
        $archive = DB::table('fms_archive_jobs')->where('id', $job)->first();
        Storage::disk('studio-test')->assertExists($archive->object_key);
        $this->patchJson($this->url($workspace, 'entries/'.$file), ['revision' => 1, 'name' => 'renamed.png'])->assertOk();
        $this->getJson($url.'/result')->assertConflict();
        $this->travel(25)->hours();
        $this->getJson($url)->assertNotFound();
        $this->artisan('media-studio:prune-archives')->assertSuccessful();
        Storage::disk('studio-test')->assertMissing($archive->object_key);
        $this->assertDatabaseMissing('fms_archive_jobs', ['id' => $job]);
    }

    public function test_archives_stream_nested_folders_and_enforce_download_permission(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Photos');
        $file = $this->file($workspace, 'inside.png', $folder);
        $outside = $this->file($workspace, 'outside.png');
        $share = $this->share($workspace, [$folder], ['allowDownload' => true, 'allowArchive' => true]);
        $bytes = $this->get($share['url'].'/download')->assertOk()->assertDownload('shared-collection.zip')->streamedContent();
        $path = tempnam(sys_get_temp_dir(), 'fms-zip-');
        file_put_contents($path, $bytes);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($path));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertCount(2, $names);
        $this->assertStringEndsWith('/inside.png', $names[1]);
        $this->assertStringNotContainsString('outside.png', implode(' ', $names));
        $zip->close();
        unlink($path);
        $this->get($share['url'].'/download?parent='.$folder)->assertOk()->assertDownload('shared-collection.zip');
        $this->getJson($share['url'].'/download?parent='.$outside)->assertNotFound();
        $this->get($this->url($workspace, 'download').'?'.http_build_query(['entries' => [$folder, $outside]]))->assertOk()->assertDownload('media.zip');
        $blocked = $this->share($workspace, [$file]);
        $this->get($blocked['url'].'/download')->assertForbidden();
        $singleOnly = $this->share($workspace, [$folder], ['allowDownload' => true]);
        $this->get($singleOnly['url'].'/entries/'.$file.'/download')->assertDownload('inside.png');
        $this->get($singleOnly['url'].'/download')->assertForbidden();
        $this->patchJson($this->url($workspace, 'shares/'.$share['id']), ['revision' => 1, 'allowArchive' => false])->assertOk();
        $this->get($share['url'].'/download')->assertForbidden();
        $this->patchJson($this->url($workspace, 'shares/'.$share['id']), ['revision' => 2, 'allowArchive' => true])->assertOk();
        config()->set('media-studio.downloads.max_archive_entries', 1);
        $this->get($share['url'].'/download')->assertStatus(413);
    }

    public function test_guest_can_open_link_and_read_only_the_shared_items(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Photos');
        $other = $this->file($workspace, 'outside.png');
        $file = $this->file($workspace, 'inside.png', $folder);
        $share = $this->share($workspace, [$folder], ['title' => '<Private photos>']);
        $token = basename($share['url']);
        $this->assertDatabaseHas('fms_shares', ['token_hash' => hash('sha256', $token)]);
        $this->assertDatabaseMissing('fms_shares', ['token_hash' => $token]);
        $this->getJson($this->url($workspace, 'shares'))->assertOk()->assertJsonMissingPath('items.0.url')->assertJsonMissingPath('items.0.token_hash');
        $this->app['auth']->forgetGuards();
        $this->getJson($this->url($workspace, 'shares'))->assertUnauthorized();
        $page = $this->get($share['url'])->assertOk()->assertSee('<Private photos>')->assertDontSee('<Private photos>', escape: false);
        $this->assertStringContainsString('no-store', $page->headers->get('Cache-Control'));
        $this->getJson($share['url'].'/entries')->assertOk()->assertJsonPath('items.0.id', $folder);
        $listing = $this->getJson($share['url'].'/entries?parent='.$folder)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $file)
            ->assertJsonPath('items.0.downloadUrl', null)->json('items.0');
        $this->getJson($share['url'].'/entries?parent='.$other)->assertNotFound();
        $this->getJson($share['url'].'/entries/'.$other.'/preview')->assertNotFound();
        $this->getJson($share['url'].'/entries/'.$file.'/download')->assertForbidden();
        $this->get($listing['previewUrl'].'?download=1', ['Range' => 'bytes=0-3'])->assertStatus(206)->assertHeader('Content-Type', 'image/webp')->assertStreamedContent('RIFF');
    }

    public function test_password_grants_are_limited_to_one_revision_and_rotation_revokes_the_old_link(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'brand.png');
        $share = $this->share($workspace, [$file], ['password' => 'private-password']);
        $path = $this->url($workspace, 'shares/'.$share['id']);
        $this->get($share['url'])->assertOk()->assertSee('password protected')->assertDontSee('brand.png');
        $locked = $this->getJson($share['url'].'/entries')->assertStatus(423);
        $this->assertStringContainsString('no-store', $locked->headers->get('Cache-Control'));
        $this->getJson($share['url'].'/entries/'.$file.'/preview')->assertStatus(423);
        $this->postJson($share['url'].'/unlock', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson($share['url'].'/unlock', ['password' => 'private-password'])->assertOk();
        $this->getJson($share['url'].'/entries')->assertOk()->assertJsonPath('items.0.id', $file);
        $this->patchJson($path, ['revision' => 1, 'allowDownload' => true])->assertOk()->assertJsonPath('revision', 2);
        $this->getJson($share['url'].'/entries')->assertStatus(423);
        $this->patchJson($path, ['revision' => 1, 'revoked' => true])->assertConflict();
        $rotated = $this->postJson($path.'/rotate', ['revision' => 2])->assertOk()->assertJsonPath('revision', 3)->json();
        $this->assertNotSame($share['url'], $rotated['url']);
        $this->getJson($share['url'].'/entries')->assertNotFound();
        $this->postJson($rotated['url'].'/unlock', ['password' => 'private-password'])->assertOk();
        $this->get($rotated['url'].'/entries/'.$file.'/download', ['Range' => 'bytes=0-3'])->assertStatus(206)->assertDownload('brand.png');
        $this->patchJson($path, ['revision' => 3, 'revoked' => true])->assertOk();
        $this->getJson($rotated['url'].'/entries')->assertNotFound();
    }

    public function test_workspace_boundaries_role_changes_and_live_folder_membership(): void
    {
        $workspace = $this->workspace();
        $otherWorkspace = $this->workspace('Other workspace');
        $folder = $this->folder($workspace, 'Approved');
        $file = $this->file($workspace, 'draft.png', $folder);
        $this->postJson($this->url($otherWorkspace, 'shares'), ['title' => 'Cross workspace', 'entryIds' => [$file]])->assertNotFound();
        $share = $this->share($workspace, [$folder], ['allowDownload' => true]);
        $content = $share['url'].'/entries/'.$file.'/download';
        $this->get($content)->assertDownload('draft.png');
        $this->patchJson($this->url($workspace, 'entries/'.$file), ['revision' => 1, 'parent' => null])->assertOk();
        $this->getJson($content)->assertNotFound();
        $this->getJson($share['url'].'/entries?parent='.$folder)->assertOk()->assertJsonCount(0, 'items');
        $this->app['auth']->forgetGuards();
        DB::table('fms_memberships')->where('workspace_id', DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'))->delete();
        $this->getJson($share['url'].'/entries')->assertNotFound();
    }

    public function test_list_filters_and_cursor_are_bound_to_the_workspace_and_view(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'photo.png');
        $one = $this->share($workspace, [$file], ['title' => 'One']);
        $two = $this->share($workspace, [$file], ['title' => 'Two']);
        $expired = $this->share($workspace, [$file], ['title' => 'Expired', 'expiresAt' => now()->addMinute()->toISOString()]);
        $this->travel(2)->minutes();
        $this->getJson($expired['url'].'/entries')->assertNotFound();
        $path = $this->url($workspace, 'shares');
        $first = $this->getJson($path.'?limit=1')->assertOk()->assertJsonPath('items.0.id', $two['id'])->json();
        $second = $this->getJson($path.'?limit=1&cursor='.rawurlencode($first['nextCursor']))->assertOk()->assertJsonPath('items.0.id', $one['id'])->json();
        $this->getJson($path.'?limit=1&cursor='.rawurlencode($second['previousCursor']))->assertOk()->assertJsonPath('items.0.id', $two['id']);
        $this->getJson($path.'?status=expired')->assertOk()->assertJsonPath('items.0.id', $expired['id']);
        $this->getJson($path.'?status=expired&cursor='.rawurlencode($first['nextCursor']))->assertUnprocessable();
        $this->getJson($path.'?cursor=invalid')->assertUnprocessable();
        $this->getJson($path.'?kind=folder')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_share_creator_tenant_access_is_rechecked_without_the_guest_tenant_context(): void
    {
        $tenant = new TenantIdentity('company', 'one');
        $resolver = new class($tenant) implements TenantResolver
        {
            public bool $allowed = true;

            public function __construct(public ?TenantIdentity $active) {}

            public function current(Principal $principal): ?TenantIdentity
            {
                return $this->active;
            }

            public function allows(Principal $principal, TenantIdentity $tenant): bool
            {
                return $this->allowed && $principal->id === 'member-uuid-001' && $tenant->id === 'one';
            }
        };
        $this->app->instance('test.share.tenant', $resolver);
        config()->set('media-studio.tenancy.resolver', 'test.share.tenant');
        $workspace = $this->workspace();
        $share = $this->share($workspace, [$this->file($workspace, 'tenant.png')]);
        $this->app['auth']->forgetGuards();
        $resolver->active = null;
        $this->getJson($share['url'].'/entries')->assertOk();
        $resolver->allowed = false;
        $this->getJson($share['url'].'/entries')->assertNotFound();
    }

    public function test_spatie_scope_and_original_fingerprint_are_checked_when_reading_a_share(): void
    {
        Schema::create('source_media', function (Blueprint $table): void {
            $table->string('id')->primary();
            $table->string('tenant');
            $table->string('file_name');
            $table->string('disk');
            $table->string('conversions_disk')->nullable();
            $table->string('mime_type');
            $table->unsignedBigInteger('size');
            $table->timestamps();
        });
        config()->set('media-studio.integrations.spatie.mounts.test-share', [
            'media_model' => SharingSourceFixture::class,
            'scope' => fn (Builder $query, object $workspace, Principal $principal): Builder => $query->where('tenant', $principal->id),
        ]);
        Storage::fake('existing-media');
        Storage::disk('existing-media')->put('existing/a-01/photo.png', 'source-image!');
        SharingSourceFixture::create(['id' => 'a-01', 'tenant' => 'member-uuid-001', 'file_name' => 'photo.png', 'disk' => 'existing-media', 'mime_type' => 'image/png', 'size' => 13]);
        $workspace = $this->workspace();
        app(SourceIndexer::class)->syncPage(new Principal('user', 'member-uuid-001'), $workspace, 'test-share');
        $file = DB::table('fms_entries')->where('kind', 'file')->value('public_id');
        $sourceFolder = DB::table('fms_entries')->where('kind', 'folder')->first();
        $nested = $this->file($workspace, 'native.png');
        // An imported workspace can contain a managed item below an external folder.
        DB::table('fms_entries')->where('public_id', $nested)->update(['parent_id' => $sourceFolder->id, 'parent_scope' => $sourceFolder->id]);
        $folderShare = $this->share($workspace, [$sourceFolder->public_id]);
        $share = $this->share($workspace, [$file], ['allowDownload' => true]);
        $this->app['auth']->forgetGuards();
        $this->get($share['url'].'/entries/'.$file.'/download')->assertStreamedContent('source-image!');
        $this->get($folderShare['url'].'/entries/'.$nested.'/preview', ['Range' => 'bytes=0-3'])->assertStatus(206)->assertStreamedContent('RIFF');
        SharingSourceFixture::whereKey('a-01')->update(['file_name' => 'changed.png']);
        $this->getJson($share['url'].'/entries/'.$file.'/download')->assertNotFound();
        SharingSourceFixture::whereKey('a-01')->update(['tenant' => 'other-member']);
        $this->getJson($share['url'].'/entries')->assertOk()->assertJsonCount(0, 'items');
        $this->getJson($share['url'].'/entries/'.$file.'/preview')->assertNotFound();
        config()->set('media-studio.integrations.spatie.mounts.test-share', null);
        $this->getJson($folderShare['url'].'/entries/'.$nested.'/preview')->assertNotFound();
    }

    public function test_invalid_entry_arrays_and_password_rate_limits_fail_closed(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'rate.png');
        $this->postJson($this->url($workspace, 'shares'), ['title' => 'Invalid', 'entryIds' => ['entry' => $file]])->assertUnprocessable();
        config()->set('media-studio.sharing.password_attempts', 2);
        $password = str_repeat('ż', 80);
        $share = $this->share($workspace, [$file], ['password' => $password]);
        $this->postJson($share['url'].'/unlock', ['password' => 'wrong'])->assertUnprocessable();
        $this->postJson($share['url'].'/unlock', ['password' => 'wrong again'])->assertUnprocessable();
        $this->postJson($share['url'].'/unlock', ['password' => $password])->assertTooManyRequests();
        $this->travel(61)->seconds();
        $this->postJson($share['url'].'/unlock', ['password' => $password])->assertOk();
    }

    public function test_guest_capabilities_follow_current_creator_permissions_without_disabling_preview(): void
    {
        config()->set('media-studio.sharing.guest_upload_enabled', true);
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Guest permissions');
        $file = $this->file($workspace, 'visible.png', $folder);
        $share = $this->share($workspace, [$folder], ['allowDownload' => true, 'allowArchive' => true, 'allowUpload' => true]);
        $this->app['auth']->forgetGuards();
        Gate::define('studio.files.download', fn () => false);
        Gate::define('studio.uploads.create', fn () => false);
        $this->getJson($share['url'].'/entries?parent='.$folder)->assertOk()
            ->assertJsonPath('allowDownload', false)->assertJsonPath('items.0.downloadUrl', null);
        $this->get($share['url'])->assertOk()->assertDontSee('Upload file')->assertDontSee('Download collection');
        $this->getJson($share['url'].'/entries/'.$file.'/download')->assertForbidden();
        $this->getJson($share['url'].'/download')->assertForbidden();
        $this->postJson($share['url'].'/uploads', ['file' => UploadedFile::fake()->image('blocked.png')])->assertForbidden();
        $this->get($share['url'].'/entries/'.$file.'/preview', ['Range' => 'bytes=0-3'])->assertStatus(206)->assertStreamedContent('RIFF');
    }

    public function test_guest_html_validation_returns_to_the_collection_without_a_referrer(): void
    {
        config()->set('media-studio.sharing.guest_upload_enabled', true);
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Guest form');
        $share = $this->share($workspace, [$folder], ['allowUpload' => true, 'password' => 'guest-password']);
        $this->app['auth']->forgetGuards();
        $this->post($share['url'].'/unlock', [])->assertRedirect($share['url'])->assertSessionHasErrors('password');
        $this->post($share['url'].'/unlock', ['password' => 'guest-password'])->assertRedirect($share['url']);
        $this->post($share['url'].'/uploads', [])->assertRedirect($share['url'])->assertSessionHasErrors('file');
        $this->get($share['url'])->assertOk()->assertSee('role="alert"', false)->assertSee('file field is required');
        $this->post($share['url'].'/uploads', ['file' => UploadedFile::fake()->create('program.exe', 1, 'application/x-msdownload')])
            ->assertRedirect($share['url'].'?parent='.$folder)->assertSessionHasErrors('file');
        $this->get($share['url'].'?parent='.$folder)->assertOk()->assertSee('file could not be uploaded');
    }

    public function test_guest_html_upload_retry_uses_the_same_receipt_and_quota(): void
    {
        config()->set('media-studio.sharing.guest_upload_enabled', true);
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Receipt');
        $share = $this->share($workspace, [$folder], ['allowUpload' => true]);
        $this->app['auth']->forgetGuards();
        $file = UploadedFile::fake()->image('receipt.png');
        $payload = ['file' => $file, 'uploadKey' => 'browser-receipt-00000001'];
        // Remove the private upload helper's previous request header for the browser-form path.
        $this->flushHeaders();
        $this->post($share['url'].'/uploads', $payload)->assertRedirect($share['url'].'?parent='.$folder)->assertSessionHas('media-studio-share-success');
        $this->post($share['url'].'/uploads', $payload)->assertRedirect($share['url'].'?parent='.$folder);
        $this->assertDatabaseHas('fms_shares', ['public_id' => $share['id'], 'guest_upload_count' => 1]);
        $this->assertSame(1, DB::table('fms_entries')->where('name', 'receipt.png')->count());
    }

    public function test_guest_worker_rechecks_sharing_permission_and_global_feature_switch(): void
    {
        config()->set('media-studio.downloads.background_enabled', true);
        config()->set('media-studio.downloads.background_disk', 'studio-test');
        Queue::fake();
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'worker.png');
        $share = $this->share($workspace, [$file], ['allowDownload' => true, 'allowArchive' => true]);
        foreach (['permission', 'feature'] as $reason) {
            $id = $this->postJson($share['url'].'/archive-jobs')->assertAccepted()->json('id');
            if ($reason === 'permission') {
                Gate::define('studio.shares.manage', fn () => false);
            } else {
                config()->set('media-studio.sharing.enabled', false);
            }
            try {
                app()->call([new PrepareArchive($id), 'handle']);
                $this->fail('Disabled sharing must prevent worker publication.');
            } catch (HttpException $error) {
                $this->assertContains($error->getStatusCode(), [403, 404]);
            }
            $this->assertDatabaseHas('fms_archive_jobs', ['id' => $id, 'state' => 'failed']);
            $this->assertSame([], Storage::disk('studio-test')->allFiles('flex-media-studio/archives'));
            Gate::define('studio.shares.manage', fn () => true);
        }
    }

    public function test_expiry_blocks_every_guest_surface_and_password_grants_do_not_cross_links(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'expires.png');
        $options = ['password' => 'guest-password', 'allowDownload' => true, 'allowArchive' => true, 'expiresAt' => now()->addMinute()->toIso8601String()];
        $share = $this->share($workspace, [$file], $options);
        $other = $this->share($workspace, [$file], $options);
        $this->app['auth']->forgetGuards();
        $this->postJson($share['url'].'/unlock', ['password' => 'guest-password'])->assertOk();
        $this->getJson($other['url'].'/entries')->assertStatus(423);
        $this->travel(61)->seconds();
        foreach (['', '/entries', '/entries/'.$file.'/preview', '/entries/'.$file.'/thumbnail', '/entries/'.$file.'/download', '/download'] as $suffix) {
            $response = $this->getJson($share['url'].$suffix)->assertNotFound();
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        }
        $this->postJson($share['url'].'/unlock', ['password' => 'guest-password'])->assertNotFound();
    }

    public function test_guest_html_locked_and_unavailable_states_are_private_and_actionable(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'protected.png');
        $share = $this->share($workspace, [$file], ['password' => 'guest-password']);
        $this->app['auth']->forgetGuards();
        $this->get($share['url'].'/entries/'.$file.'/preview')->assertRedirect($share['url']);
        config()->set('media-studio.sharing.password_attempts', 1);
        $this->post($share['url'].'/unlock', ['password' => 'wrong'])->assertRedirect($share['url']);
        $this->post($share['url'].'/unlock', ['password' => 'wrong'])->assertTooManyRequests()->assertSee('Please try again shortly');
        DB::table('fms_shares')->where('public_id', $share['id'])->update(['revoked_at' => now()]);
        $response = $this->get($share['url'])->assertNotFound()->assertSee('Collection unavailable')->assertDontSee('protected.png');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('no-referrer', $response->headers->get('Referrer-Policy'));
        $this->assertStringContainsString("frame-ancestors 'none'", $response->headers->get('Content-Security-Policy'));
    }

    public function test_guest_cursor_is_bound_to_collection_folder_and_revision(): void
    {
        $workspace = $this->workspace();
        $folder = $this->folder($workspace, 'Pages');
        $this->file($workspace, 'one.png', $folder);
        $this->file($workspace, 'two.png', $folder);
        $share = $this->share($workspace, [$folder]);
        $other = $this->share($workspace, [$folder]);
        $cursor = $this->getJson($share['url'].'/entries?parent='.$folder.'&limit=1')->assertOk()->assertJsonCount(1, 'items')->json('nextCursor');
        $this->assertNotNull($cursor);
        $this->app['auth']->forgetGuards();
        $this->getJson($share['url'].'/entries?parent='.$folder.'&limit=1&cursor='.urlencode($cursor))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($other['url'].'/entries?parent='.$folder.'&cursor='.urlencode($cursor))->assertUnprocessable();
        $this->getJson($share['url'].'/entries?cursor='.urlencode($cursor))->assertUnprocessable();
        DB::table('fms_shares')->where('public_id', $share['id'])->increment('revision');
        $this->getJson($share['url'].'/entries?parent='.$folder.'&cursor='.urlencode($cursor))->assertUnprocessable();
    }

    public function test_engagement_is_opt_in_bounded_and_private_to_managers(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'analytics.png');
        $private = $this->share($workspace, [$file]);
        $public = $this->share($workspace, [$file], ['visibility' => 'publish', 'collectAnalytics' => true, 'allowIndexing' => true]);
        $this->get($private['url'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        config(['media-studio.sharing.trusted_country_header' => 'X-Test-Country']);
        $this->withHeader('X-Test-Country', 'PL')->get($public['url'])->assertOk()->assertHeader('X-Robots-Tag', 'index, follow');
        $this->get($public['url'].'/entries')->assertOk();
        $this->assertSame(1, (int) DB::table('fms_share_daily')->sum('visits'));

        $share = DB::table('fms_shares')->where('public_id', $public['id'])->first();
        $ticket = app(ShareEngagement::class)->visit(request(), $share);
        $this->assertNotNull($ticket);
        $this->travel(30)->seconds();
        $this->postJson($public['url'].'/engagement', ['ticket' => $ticket, 'seconds' => 18])->assertNoContent();
        $this->postJson($public['url'].'/engagement', ['ticket' => $ticket, 'seconds' => 18])->assertNoContent();
        DB::table('fms_share_daily')->where('share_id', $share->id)->increment('visits', 2000000);
        $expected = (int) DB::table('fms_share_daily')->sum('visits');
        $report = $this->url($workspace, 'shares/'.$public['id'].'/analytics');
        $this->getJson($report.'?days=90')->assertOk()->assertJsonCount(90, 'points')
            ->assertJsonPath('visits', $expected)->assertJsonPath('measuredVisits', 1)->assertJsonPath('averageSeconds', 18)
            ->assertJsonPath('countries.0.country', 'PL')->assertHeader('Cache-Control', 'no-store, private');
        $this->getJson($report.'?days=365')->assertUnprocessable();
        $this->getJson($this->url($workspace, 'shares/'.$public['id'].'/link'))->assertOk()->assertJsonPath('url', $public['url']);
        $this->getJson($this->url($workspace, 'shares?entry='.$file.'&visibility=publish'))->assertOk()->assertJsonCount(1, 'items')->assertJsonMissingPath('items.0.url');
        $this->patchJson($this->url($workspace, 'shares/'.$public['id']), ['revision' => 1, 'password' => 'password123'])->assertOk()->assertJsonPath('allowIndexing', false);
        $this->get($public['url'])->assertOk()->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->deleteJson($this->url($workspace, 'shares/'.$public['id']), ['revision' => 2])->assertOk();
        $this->assertSame(0, DB::table('fms_share_daily')->count());
    }

    public function test_invitation_requires_recipient_email_and_enforces_roles(): void
    {
        $workspace = $this->workspace();
        $file = $this->file($workspace, 'invited.png');
        $other = $this->file($workspace, 'outside.png');
        Mail::shouldReceive('raw')->once();
        $result = $this->postJson($this->url($workspace, 'shares/invitations'), [
            'emails' => ['Guest@example.test'], 'entryIds' => [$file], 'title' => 'Invitation', 'permission' => 'comment',
        ])->assertCreated()->assertJsonPath('items.0.recipientEmail', 'guest@example.test')->json();
        $id = $result['items'][0]['id'];
        $url = $this->getJson($this->url($workspace, 'shares/'.$id.'/link'))->assertOk()->json('url');
        $this->getJson($url.'/entries')->assertForbidden();
        $this->patchJson($this->url($workspace, 'shares/'.$id), ['revision' => 1, 'recipientPermission' => 'edit'])->assertOk();

        config(['media-studio.principals.invitee' => ShareEmailFixture::class]);
        $this->actingAs(new ShareEmailFixture(['id' => 'guest', 'email' => 'wrong@example.test', 'verified' => true]));
        $this->getJson($url.'/entries')->assertForbidden();
        $this->actingAs(new ShareEmailFixture(['id' => 'guest', 'email' => 'guest@example.test', 'verified' => false]));
        $this->getJson($url.'/entries')->assertForbidden();
        $this->actingAs(new ShareEmailFixture(['id' => 'guest', 'email' => 'Guest@example.test', 'verified' => true]));
        $this->getJson($url.'/entries')->assertOk()->assertJsonCount(1, 'items');
        $this->post($url.'/comments', ['body' => 'Looks good'])->assertRedirect($url);
        $this->postJson($url.'/entries/'.$other.'/rename', ['name' => 'illegal.png', 'revision' => 1])->assertNotFound();
        $revision = DB::table('fms_entries')->where('public_id', $file)->value('revision');
        $this->post($url.'/entries/'.$file.'/rename', ['name' => 'approved.png', 'revision' => $revision])->assertRedirect($url);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $file, 'name' => 'approved.png']);
        $this->getJson($this->url($workspace, 'shares'))->assertNotFound();
    }

    public function test_published_files_can_be_copied_only_to_an_authorized_workspace(): void
    {
        $source = $this->workspace();
        $target = $this->workspace('Destination');
        $file = $this->file($source, 'copy.png');
        $disabled = $this->share($source, [$file], ['visibility' => 'publish', 'allowDownload' => true]);
        $this->postJson($disabled['url'].'/entries/'.$file.'/copy', ['workspace' => $target])->assertForbidden();
        $enabled = $this->share($source, [$file], ['visibility' => 'publish', 'allowDownload' => true, 'allowCopy' => true]);
        $this->get($enabled['url'])->assertOk()->assertSee('Copy to Studio');
        $this->post($enabled['url'].'/entries/'.$file.'/copy', ['workspace' => $target])->assertRedirect($enabled['url']);
        $this->getJson($this->url($target))->assertOk()->assertJsonPath('items.0.name', 'copy.png');
    }

    private function share(string $workspace, array $ids, array $options = []): array
    {
        return $this->postJson($this->url($workspace, 'shares'), ['title' => 'Brand images', 'entryIds' => $ids, ...$options])->assertCreated()->json();
    }

    private function folder(string $workspace, string $name): string
    {
        return $this->postJson($this->url($workspace, 'folders'), ['name' => $name])->assertCreated()->json('id');
    }

    private function file(string $workspace, string $name, ?string $parent = null): string
    {
        return $this->withHeader('Idempotency-Key', (string) Str::ulid())->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image($name, 32, 24), 'parent' => $parent,
        ])->assertCreated()->json('id');
    }
}

/** A local, mutable source for permission and fingerprint regression tests. */
final class SharingSourceFixture extends Model
{
    protected $table = 'source_media';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    public function getPathRelativeToRoot(): string
    {
        return 'existing/'.$this->getKey().'/'.$this->file_name;
    }
}

final class ShareEmailFixture extends GenericUser
{
    public function hasVerifiedEmail(): bool
    {
        return (bool) $this->verified;
    }

    public function getEmailForVerification(): string
    {
        return $this->email;
    }
}
