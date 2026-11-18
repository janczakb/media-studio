<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Testing\TestResponse;
use Laravel\Mcp\Server\McpServiceProvider;
use UseFlex\MediaStudio\MediaStudioServiceProvider;

final class McpTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [McpServiceProvider::class, MediaStudioServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        parent::defineEnvironment($app);
        $app['config']->set('media-studio.mcp.enabled', true);
        $app['config']->set('media-studio.mcp.middleware', ['web', 'auth']);
        $app['config']->set('media-studio.mcp.authentication', 'session');
    }

    private function rpc(string $method, array $params = []): TestResponse
    {
        return $this->postJson('/media-studio/mcp', ['jsonrpc' => '2.0', 'id' => 1, 'method' => $method, 'params' => $params]);
    }

    private function callTool(string $name, array $arguments = []): TestResponse
    {
        return $this->rpc('tools/call', ['name' => $name, 'arguments' => (object) $arguments]);
    }

    private function toolData(TestResponse $response): array
    {
        $response->assertOk()->assertJsonMissingPath('error');
        $this->assertFalse($response->json('result.isError') ?? false, $response->getContent());

        return json_decode($response->json('result.content.0.text'), true, 32, JSON_THROW_ON_ERROR);
    }

    public function test_real_sdk_initialization_and_tool_discovery_hide_disabled_search(): void
    {
        $this->rpc('initialize', ['protocolVersion' => '2025-11-25', 'capabilities' => (object) [], 'clientInfo' => ['name' => 'Studio test', 'version' => '1']])->assertOk()->assertJsonPath('result.serverInfo.name', 'FLEX Media Studio');
        $tools = $this->rpc('tools/list')->assertOk()->json('result.tools');
        $this->assertSame(['studio_list_workspaces', 'studio_list_files', 'studio_get_file'], array_column($tools, 'name'));
        foreach ($tools as $tool) {
            $this->assertTrue($tool['annotations']['readOnlyHint']);
        }
        config()->set('media-studio.mcp.tools', ['studio_get_file']);
        $this->assertSame(['studio_get_file'], array_column($this->rpc('tools/list')->assertOk()->json('result.tools'), 'name'));
    }

    public function test_connection_address_keeps_the_mcp_path_when_example_config_is_partial(): void
    {
        config()->set('media-studio.mcp', ['enabled' => true, 'authentication' => 'session']);
        $this->getJson('/media-studio/api/v1/ai')->assertOk()->assertJsonPath('mcp.endpoint', 'http://localhost/media-studio/mcp');
    }

    public function test_reads_registered_files_without_exposing_storage_or_mutating_data(): void
    {
        $workspace = $this->workspace();
        $folder = $this->postJson($this->url($workspace, 'folders'), ['name' => 'Brand assets'])->assertCreated()->json('id');
        $before = DB::table('fms_entries')->count();
        $this->assertSame($workspace, $this->toolData($this->callTool('studio_list_workspaces'))['items'][0]['id']);
        $files = $this->toolData($this->callTool('studio_list_files', ['workspace' => $workspace]));
        $this->assertSame($folder, $files['items'][0]['id']);
        $this->assertArrayNotHasKey('thumbnailUrl', $files['items'][0]);
        $file = $this->toolData($this->callTool('studio_get_file', ['workspace' => $workspace, 'entry' => $folder]));
        $this->assertSame('Brand assets', $file['file']['name']);
        $this->assertArrayNotHasKey('disk', $file['file']);
        $this->assertSame($before, DB::table('fms_entries')->count());
        $this->assertCount(1, DB::table('fms_workspaces')->get());
    }

    public function test_access_is_rechecked_per_call_and_global_feature_can_be_disabled(): void
    {
        $workspace = $this->workspace();
        Gate::define('studio.mcp.use', fn () => false);
        $this->rpc('tools/list')->assertForbidden();
        Gate::define('studio.mcp.use', fn () => true);
        config()->set('media-studio.mcp.enabled', false);
        $this->callTool('studio_list_files', ['workspace' => $workspace])->assertForbidden();
        $this->getJson('/media-studio/api/v1/ai')->assertOk()->assertJsonPath('mcp.endpoint', null)->assertJsonPath('mcp.canUse', false);
    }

    public function test_workspace_membership_and_configured_allowlist_cannot_be_bypassed(): void
    {
        $workspace = $this->workspace();
        config()->set('media-studio.mcp.workspaces', []);
        $this->assertSame([], $this->toolData($this->callTool('studio_list_workspaces'))['items']);
        $this->callTool('studio_list_files', ['workspace' => $workspace])->assertOk()->assertJsonPath('result.isError', true);
        config()->set('media-studio.mcp.workspaces', null);
        $this->actingAs(new GenericUser(['id' => 'different-account']));
        $this->assertSame([], $this->toolData($this->callTool('studio_list_workspaces'))['items']);
        $this->callTool('studio_list_files', ['workspace' => $workspace])->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_pagination_is_bounded_and_cursor_is_bound_to_principal(): void
    {
        config()->set('media-studio.workspaces.max_owned', 30);
        for ($i = 0; $i < 26; $i++) {
            $this->workspace('Library '.$i);
        }
        $page = $this->toolData($this->callTool('studio_list_workspaces'));
        $this->assertCount(25, $page['items']);
        $this->assertTrue($page['hasMore']);
        $next = $this->toolData($this->callTool('studio_list_workspaces', ['cursor' => $page['nextCursor']]));
        $this->assertCount(1, $next['items']);
        $this->assertNotContains($next['items'][0]['id'], array_column($page['items'], 'id'));
        $this->actingAs(new GenericUser(['id' => 'different-account']));
        $this->callTool('studio_list_workspaces', ['cursor' => $page['nextCursor']])->assertOk()->assertJsonPath('result.isError', true);
    }

    public function test_validation_origin_guard_and_ai_panel_authorization(): void
    {
        $this->callTool('studio_list_files', ['workspace' => '../../private'])->assertOk()->assertJsonPath('result.isError', true);
        $this->withHeader('Origin', 'https://untrusted.example')->rpc('tools/list')->assertForbidden();
        $this->flushHeaders();
        $this->getJson('/media-studio/api/v1/ai')->assertOk()->assertJsonPath('mcp.authentication', 'session')->assertJsonPath('features.imageOcr.implemented', true)->assertJsonPath('features.imageOcr.enabled', false)->assertHeader('Cache-Control', 'no-store, private');
        Gate::define('studio.ai.view', fn () => false);
        $this->getJson('/media-studio/api/v1/ai')->assertForbidden();
    }
}
