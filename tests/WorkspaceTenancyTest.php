<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpKernel\Exception\HttpException;
use UseFlex\MediaStudio\Access\TenantResolver;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Processing\GenerateThumbnail;
use UseFlex\MediaStudio\Processing\ThumbnailProcessor;

final class WorkspaceTenancyTest extends TestCase
{
    private const LIST = '/media-studio/api/v1/workspaces';

    public function test_workspace_endpoints_require_the_active_authorized_tenant_even_for_a_member_of_both(): void
    {
        $standalone = $this->workspace('Standalone');
        $first = new TenantIdentity('company', 'Account-UUID');
        $second = new TenantIdentity('company', 'account-uuid');
        $resolver = $this->resolver($first, $second);
        $resolver->active = $first;
        $firstWorkspace = $this->postJson(self::LIST, ['name' => 'Company one', 'tenant_id' => $second->id])->assertCreated()->json('id');
        $this->assertDatabaseHas('fms_workspaces', ['public_id' => $firstWorkspace, 'tenant_type' => 'company', 'tenant_id' => $first->id, 'tenant_key' => $first->key()]);
        $file = $this->withHeader('Idempotency-Key', 'tenant-upload-000001')->postJson($this->url($firstWorkspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('company.txt', 'Private company file.'),
        ])->assertCreated()->json();
        config()->set('media-studio.upload.sessions.enabled', true);
        $session = $this->postJson($this->url($firstWorkspace, 'upload-sessions'), [
            'name' => 'another.txt', 'size' => 3, 'key' => 'tenant-session-000001',
        ])->assertCreated()->json('id');
        $versionPath = $this->url($firstWorkspace, 'entries/'.$file['id'].'/versions/'.$file['versionId'].'/content');
        $this->get($versionPath)->assertOk();
        $this->getJson($this->url($standalone))->assertNotFound();

        $resolver->active = $second;
        $secondWorkspace = $this->workspace('Company two');
        $this->getJson(self::LIST)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $secondWorkspace);
        $this->withHeader('X-Tenant-ID', $first->id)->getJson($this->url($firstWorkspace))->assertNotFound();
        $this->getJson($versionPath)->assertNotFound();
        $this->getJson($this->url($firstWorkspace, 'upload-sessions/'.$session))->assertNotFound();
        $this->deleteJson($this->url($firstWorkspace, 'upload-sessions/'.$session))->assertNotFound();
        $this->putJson($this->url($firstWorkspace, 'entries/'.$file['id'].'/star'), ['starred' => true])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, 'references/resolve'), ['references' => [['assetId' => $file['id']]]])->assertNotFound();
        $entryPath = 'entries/'.$file['id'];
        foreach ([$entryPath, $entryPath.'/neighbors?direction=next', $entryPath.'/thumbnail', $entryPath.'/preview',
            $entryPath.'/versions/'.$file['versionId'].'/renditions/thumbnail-v1', 'upload-policy'] as $path) {
            $this->getJson($this->url($firstWorkspace, $path))->assertNotFound();
        }
        $this->postJson($this->url($firstWorkspace, 'entries/status'), ['ids' => [$file['id']]])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, 'folders'), ['name' => 'Cross tenant'])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, 'folders/resolve'), ['paths' => ['Cross/tenant']])->assertNotFound();
        $this->patchJson($this->url($firstWorkspace, $entryPath), ['revision' => 1, 'name' => 'Changed.txt'])->assertNotFound();
        foreach (['trash', 'restore', 'visit'] as $action) {
            $this->postJson($this->url($firstWorkspace, $entryPath.'/'.$action), ['revision' => 1])->assertNotFound();
        }
        $this->postJson($this->url($firstWorkspace, 'entries/bulk'), [
            'operation' => 'move', 'parent' => null, 'items' => [['id' => $file['id'], 'revision' => 1]],
        ])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, 'sources/spatie/catalog/sync'), [])->assertNotFound();
        $this->withHeader('Idempotency-Key', 'tenant-denied-upload-001')->postJson($this->url($firstWorkspace, 'uploads'), [
            'file' => UploadedFile::fake()->createWithContent('denied.txt', 'No tenant grant'),
        ])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, 'upload-sessions'), [
            'name' => 'denied.txt', 'size' => 3, 'key' => 'tenant-denied-session-001',
        ])->assertNotFound();
        $sessionPath = 'upload-sessions/'.$session;
        $this->call('PUT', $this->url($firstWorkspace, $sessionPath.'/parts/1'), [], [], [], ['CONTENT_TYPE' => 'application/octet-stream'], 'abc')->assertNotFound();
        $this->postJson($this->url($firstWorkspace, $sessionPath.'/parts/1/sign'), ['sha256' => hash('sha256', 'abc')])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, $sessionPath.'/parts/1/receipt'), ['etag' => '"example"'])->assertNotFound();
        $this->postJson($this->url($firstWorkspace, $sessionPath.'/complete'))->assertNotFound();

        $resolver->active = $first;
        $resolver->allowed = [$second->key()];
        $this->getJson($versionPath)->assertNotFound();
        $resolver->active = null;
        $this->getJson(self::LIST)->assertNotFound();
        $this->postJson(self::LIST, ['name' => 'No context', 'tenant_id' => $first->id])->assertNotFound();
        config()->set('media-studio.tenancy.resolver', null);
        $this->getJson(self::LIST)->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $standalone);
        $this->getJson($versionPath)->assertNotFound();
    }

    public function test_worker_restores_persisted_tenant_and_refuses_wrong_missing_or_revoked_context(): void
    {
        Bus::fake([GenerateThumbnail::class]);
        $first = new TenantIdentity('company', 'shared-id');
        $second = new TenantIdentity('division', 'shared-id');
        $resolver = $this->resolver($first, $second);
        $resolver->active = $first;
        $workspace = $this->workspace('Company');
        $file = $this->withHeader('Idempotency-Key', 'tenant-image-000001')->postJson($this->url($workspace, 'uploads'), [
            'file' => UploadedFile::fake()->image('source.png', 32, 24),
        ])->assertCreated()->json();
        $job = Bus::dispatched(GenerateThumbnail::class)->sole();
        $this->assertSame([$first->type, $first->id], [$job->tenantType, $job->tenantId]);

        $resolver->active = $second;
        $processor = app(ThumbnailProcessor::class);
        foreach ([null, $second] as $incorrect) {
            $this->assertWorkerDenied(new GenerateThumbnail($workspace, $file['versionId'], $job->principalType, $job->principalId,
                $incorrect?->type, $incorrect?->id), $processor);
        }
        $resolver->allowed = [$second->key()];
        $this->assertWorkerDenied($job, $processor);
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertDatabaseHas('fms_renditions', ['version_id' => $file['versionId'], 'state' => 'queued', 'attempts' => 0]);

        $resolver->active = null;
        $resolver->allowed = [$first->key(), $second->key()];
        $resolver->remainingChecks = 2;
        $this->assertWorkerDenied($job, $processor);
        $this->assertCount(1, Storage::disk('studio-test')->allFiles());
        $this->assertDatabaseMissing('fms_renditions', ['version_id' => $file['versionId'], 'state' => 'ready']);
        $resolver->remainingChecks = null;
        $job->handle($processor);
        $this->assertSame(2, DB::table('fms_renditions')->where('version_id', $file['versionId'])->where('state', 'ready')->count());

        $resolver->active = $second;
        $this->getJson($this->url($workspace, 'entries/'.$file['id'].'/preview'))->assertNotFound();
        $resolver->active = $first;
        $this->get($this->url($workspace, 'entries/'.$file['id'].'/preview'))->assertOk();
    }

    private function assertWorkerDenied(GenerateThumbnail $job, ThumbnailProcessor $processor): void
    {
        try {
            $job->handle($processor);
            $this->fail('The worker used a tenant without permission.');
        } catch (HttpException $error) {
            $this->assertSame(404, $error->getStatusCode());
        }
    }

    private function resolver(TenantIdentity ...$allowed): TestTenantResolver
    {
        $resolver = new TestTenantResolver(array_map(fn (TenantIdentity $tenant): string => $tenant->key(), $allowed));
        app()->instance(TestTenantResolver::class, $resolver);
        config()->set('media-studio.tenancy.resolver', TestTenantResolver::class);

        return $resolver;
    }
}

final class TestTenantResolver implements TenantResolver
{
    public ?TenantIdentity $active = null;

    public ?int $remainingChecks = null;

    public function __construct(public array $allowed) {}

    public function current(Principal $principal): ?TenantIdentity
    {
        return $this->active;
    }

    public function allows(Principal $principal, TenantIdentity $tenant): bool
    {
        return ($this->remainingChecks === null || $this->remainingChecks-- > 0)
            && $principal->type === 'user' && $principal->id === 'member-uuid-001'
            && in_array($tenant->key(), $this->allowed, true);
    }
}
