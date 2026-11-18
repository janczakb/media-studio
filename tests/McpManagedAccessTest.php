<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\McpServiceProvider;
use UseFlex\MediaStudio\MediaStudioServiceProvider;

final class McpManagedAccessTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, MediaStudioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-studio.mcp.enabled', true);
        $app['config']->set('media-studio.mcp.authentication', 'managed');
    }

    private function grant(string $id, array $tools, ?array $workspaces = null, array $overrides = []): string
    {
        $secret = hash('sha256', 'test-only:'.$id);
        config()->set('media-studio.mcp.connections.'.$id, [
            'token_hash' => hash('sha256', $secret),
            'expires_at' => time() + 600,
            'principal_type' => 'user',
            'principal_id' => 'member-uuid-001',
            'tenant_key' => null,
            'workspaces' => $workspaces,
            'tools' => $tools,
            ...$overrides,
        ]);

        return 'fms_mcp_'.$id.'.'.$secret;
    }

    private function rpc(string $token, string $method, array $parameters = []): TestResponse
    {
        return $this->postJson('/media-studio/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $parameters], ['Authorization' => 'Bearer '.$token]);
    }

    private function callTool(string $token, string $name, array $data): TestResponse
    {
        return $this->rpc($token, 'tools/call', ['name' => $name, 'arguments' => (object) $data]);
    }

    private function toolData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError') ?? false, $response->getContent());

        return $response->json('result.structuredContent') ?? json_decode($response->json('result.content.0.text'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_connection_expires_and_only_exposes_explicitly_granted_tools_and_workspaces(): void
    {
        $first = $this->workspace('First');
        $second = $this->workspace('Second');
        $token = $this->grant('reader', ['studio_list_workspaces', 'studio_list_files', '*'], [$first]);
        $this->rpc('', 'tools/list')->assertUnauthorized()->assertHeader('WWW-Authenticate', 'Bearer realm="mcp", error="invalid_token"');
        $tools = $this->rpc($token, 'tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['studio_list_workspaces', 'studio_list_files'], array_column($tools, 'name'));
        $this->assertSame([$first], array_column($this->toolData($this->callTool($token, 'studio_list_workspaces', []))['items'], 'id'));
        $this->callTool($token, 'studio_list_files', ['workspace' => $second])->assertJsonPath('result.isError', true);
        $this->callTool($token, 'studio_create_folder', ['workspace' => $first, 'name' => 'Denied'])->assertJsonMissingPath('result.content.0.text');
        config()->set('media-studio.mcp.connections.reader.tenant_key', str_repeat('f', 64));
        $this->rpc($token, 'tools/list')->assertForbidden();
        config()->set('media-studio.mcp.connections.reader.tenant_key', null);
        config()->set('media-studio.mcp.connections.reader.expires_at', time() - 1);
        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_one_client_can_write_in_one_workspace_and_only_read_in_another(): void
    {
        $first = $this->workspace('First');
        $second = $this->workspace('Second');
        $token = $this->grant('editor', ['studio_list_files', 'studio_create_folder'], [$first, $second], [
            'workspace_tools' => [$second => ['studio_list_files']],
        ]);
        $this->toolData($this->callTool($token, 'studio_create_folder', ['workspace' => $first, 'name' => 'Writable']));
        $this->toolData($this->callTool($token, 'studio_list_files', ['workspace' => $second]));
        $this->callTool($token, 'studio_create_folder', ['workspace' => $second, 'name' => 'Forbidden'])->assertJsonPath('result.isError', true);
        $this->assertDatabaseCount('fms_entries', 1);
        config()->set('media-studio.mcp.connections.editor.enabled', false);
        $this->rpc($token, 'tools/list')->assertUnauthorized();
    }

    public function test_mcp_creates_a_workspace_once_and_uses_native_permissions_for_writes(): void
    {
        $token = $this->grant('organizer', ['studio_create_workspace', 'studio_create_folder', 'studio_rename_entry', 'studio_trash_entry', 'studio_restore_entry']);
        $input = ['name' => 'MCP Studio', 'key' => 'workspace-request-001'];
        $workspace = $this->toolData($this->callTool($token, 'studio_create_workspace', $input))['id'];
        $this->assertSame($workspace, $this->toolData($this->callTool($token, 'studio_create_workspace', $input))['id']);
        $this->callTool($token, 'studio_create_workspace', [...$input, 'name' => 'Different'])->assertJsonPath('result.isError', true);
        $this->assertSame(1, DB::table('fms_workspaces')->count());

        $folder = $this->toolData($this->callTool($token, 'studio_create_folder', ['workspace' => $workspace, 'name' => 'New Folder']));
        $entry = ['workspace' => $workspace, 'entry' => $folder['id'], 'revision' => $folder['revision']];
        $changed = $this->toolData($this->callTool($token, 'studio_rename_entry', [...$entry, 'name' => 'Renamed']));
        $this->callTool($token, 'studio_trash_entry', $entry)->assertJsonPath('result.isError', true);
        $entry['revision'] = $changed['revision'];
        Gate::define('studio.files.trash', fn () => false);
        $this->callTool($token, 'studio_trash_entry', $entry)->assertJsonPath('result.isError', true);
        Gate::define('studio.files.trash', fn () => true);
        $trashed = $this->toolData($this->callTool($token, 'studio_trash_entry', $entry));
        $this->assertNotNull($trashed['trashedAt']);
        $this->assertNull($this->toolData($this->callTool($token, 'studio_restore_entry', [...$entry, 'revision' => $trashed['revision']]))['trashedAt']);
    }

    public function test_image_import_publishes_named_variants_under_one_version(): void
    {
        $workspace = $this->workspace();
        $token = $this->grant('image-importer', ['studio_prepare_image_import', 'studio_resolve_import_folder'], [$workspace]);
        $folderArgs = ['workspace' => $workspace, 'path' => 'Catalog/Chairs'];
        $folder = $this->toolData($this->callTool($token, 'studio_resolve_import_folder', $folderArgs))['id'];
        $this->assertSame($folder, $this->toolData($this->callTool($token, 'studio_resolve_import_folder', $folderArgs))['id']);
        $path = $this->toolData($this->callTool($token, 'studio_prepare_image_import', ['workspace' => $workspace]))['uploadPath'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP6sAAAAASUVORK5CYII=');
        $headers = ['Accept' => 'application/json', 'Authorization' => 'Bearer '.$token, 'Idempotency-Key' => 'image-import-test-01'];
        $send = fn (string $role) => $this->post($path, [
            'file' => UploadedFile::fake()->createWithContent('chair.png', $png),
            'variants' => ['card' => UploadedFile::fake()->createWithContent('chair-thumb.png', $png)],
            'roles' => ['thumbnail' => $role, 'preview' => 'card'],
            'parent' => $folder,
        ], $headers);

        $send('missing')->assertUnprocessable();
        $this->assertDatabaseCount('fms_asset_versions', 0);
        $entry = $send('card')->assertCreated()->json();
        $this->assertSame($entry['id'], $send('card')->assertCreated()->json('id'));
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $this->assertDatabaseCount('fms_renditions', 1);
        $this->assertDatabaseHas('fms_renditions', ['preset' => 'import-card', 'version_id' => $entry['versionId'], 'state' => 'ready']);
        $this->assertDatabaseHas('fms_entries', ['public_id' => $entry['id'], 'thumbnail_preset' => 'import-card', 'preview_preset' => 'import-card']);
        $variant = DB::table('fms_renditions')->where('version_id', $entry['versionId'])->first();
        $this->assertSame($png, Storage::disk('studio-test')->get($variant->object_key));
    }

    public function test_binary_upload_and_comment_lifecycle_share_the_studio_records(): void
    {
        $workspace = $this->workspace();
        $token = $this->grant('reviewer', ['studio_prepare_file_upload', 'studio_list_comments', 'studio_create_comment', 'studio_edit_comment', 'studio_resolve_comment', 'studio_delete_comment'], [$workspace]);
        $path = $this->toolData($this->callTool($token, 'studio_prepare_file_upload', ['workspace' => $workspace]))['uploadPath'];
        $readOnly = $this->grant('policy-only', ['studio_get_upload_policy'], [$workspace]);
        $headers = ['Accept' => 'application/json', 'Idempotency-Key' => 'studio-upload-test-01'];
        $this->post($path, ['file' => UploadedFile::fake()->createWithContent('report.txt', 'example')], [...$headers, 'Authorization' => 'Bearer '.$readOnly])->assertForbidden();
        $entry = $this->post($path, ['file' => UploadedFile::fake()->createWithContent('report.txt', 'example')], [...$headers, 'Authorization' => 'Bearer '.$token])->assertCreated()->json('id');
        $args = ['workspace' => $workspace, 'entry' => $entry];
        $message = [...$args, 'body' => 'Please review', 'key' => 'comment-request-0001'];
        $comment = $this->toolData($this->callTool($token, 'studio_create_comment', $message));
        $this->assertSame($comment['id'], $this->toolData($this->callTool($token, 'studio_create_comment', $message))['id']);
        $this->assertSame('Please review', $this->toolData($this->callTool($token, 'studio_list_comments', $args))['items'][0]['body']);
        $change = [...$args, 'comment' => $comment['id'], 'revision' => $comment['revision']];
        $edited = $this->toolData($this->callTool($token, 'studio_edit_comment', [...$change, 'body' => 'Reviewed']));
        $resolved = $this->toolData($this->callTool($token, 'studio_resolve_comment', [...$change, 'revision' => $edited['revision'], 'resolved' => true]));
        $this->assertTrue($resolved['resolved']);
        $this->assertTrue($this->toolData($this->callTool($token, 'studio_delete_comment', [...$change, 'revision' => $resolved['revision']]))['deleted']);
        $this->assertDatabaseCount('fms_comments', 0);
    }

    public function test_bulk_and_download_permissions_are_independent(): void
    {
        $workspace = $this->workspace();
        $token = $this->grant('operator', [
            'studio_prepare_file_upload', 'studio_prepare_file_download', 'studio_list_trash',
            'studio_bulk_trash', 'studio_bulk_restore', 'studio_replace_tags',
        ], [$workspace]);
        $upload = $this->toolData($this->callTool($token, 'studio_prepare_file_upload', ['workspace' => $workspace]));
        $headers = ['Authorization' => 'Bearer '.$token, 'Accept' => 'application/json'];
        $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+jP6sAAAAASUVORK5CYII=');
        $entry = $this->post($upload['uploadPath'], ['file' => UploadedFile::fake()->createWithContent('sample.png', $png)], [
            ...$headers, 'Idempotency-Key' => 'bulk-test-upload-0001',
        ])->assertCreated()->json();
        $tagged = $this->toolData($this->callTool($token, 'studio_replace_tags', [
            'workspace' => $workspace, 'entry' => $entry['id'], 'revision' => $entry['revision'], 'tags' => ['Marketing'],
        ]));
        $download = $this->toolData($this->callTool($token, 'studio_prepare_file_download', ['workspace' => $workspace, 'entry' => $entry['id']]));
        $stream = $this->get($download['downloadPath'], $headers)->assertOk()->assertHeader('Content-Disposition', 'attachment; filename=sample.png');
        $this->assertSame($png, $stream->streamedContent());
        Gate::define('studio.files.download', fn () => false);
        $this->get($download['downloadPath'], $headers)->assertForbidden();
        $item = ['id' => $entry['id'], 'revision' => $tagged['revision']];
        $trashed = $this->toolData($this->callTool($token, 'studio_bulk_trash', ['workspace' => $workspace, 'items' => [$item]]))['items'][0];
        $this->assertNotNull($trashed['trashedAt']);
        $this->assertSame([$entry['id']], array_column($this->toolData($this->callTool($token, 'studio_list_trash', ['workspace' => $workspace]))['items'], 'id'));
        $this->assertNull($this->toolData($this->callTool($token, 'studio_bulk_restore', ['workspace' => $workspace,
            'items' => [['id' => $entry['id'], 'revision' => $trashed['revision']]],
        ]))['items'][0]['trashedAt']);
    }

    public function test_chunk_upload_is_bound_to_one_client_even_when_both_have_the_same_account(): void
    {
        config()->set('media-studio.upload.sessions.enabled', true);
        $workspace = $this->workspace();
        $tools = ['studio_begin_upload', 'studio_get_upload', 'studio_complete_upload'];
        $first = $this->grant('first', $tools, [$workspace]);
        $second = $this->grant('second', $tools, [$workspace]);
        $data = 'private file content';
        $session = $this->toolData($this->callTool($first, 'studio_begin_upload', [
            'workspace' => $workspace, 'name' => 'chunk.txt', 'size' => strlen($data), 'key' => 'studio-chunk-test-01',
        ]));
        $args = ['workspace' => $workspace, 'session' => $session['id']];
        $this->callTool($second, 'studio_get_upload', $args)->assertJsonPath('result.isError', true);
        $path = $session['partPathPrefix'].'0';
        $part = fn (string $token) => $this->call('PUT', $path, [], [], [], [
            'CONTENT_TYPE' => 'application/octet-stream', 'HTTP_ACCEPT' => 'application/json',
            'HTTP_AUTHORIZATION' => 'Bearer '.$token, 'HTTP_X_PART_SHA256' => hash('sha256', $data),
        ], $data);
        $part($second)->assertNotFound();
        $this->getJson($this->url($workspace, 'upload-sessions/'.$session['id']))->assertNotFound();
        $part($first)->assertOk();
        $this->assertSame([0], $this->toolData($this->callTool($first, 'studio_get_upload', $args))['acceptedParts']);
        $entry = $this->toolData($this->callTool($first, 'studio_complete_upload', $args));
        $this->assertSame('chunk.txt', $entry['name']);
        $this->assertSame($entry['id'], $this->toolData($this->callTool($first, 'studio_complete_upload', $args))['id']);
    }
}
