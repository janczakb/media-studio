<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use UseFlex\MediaStudio\Library\MediaReferences;

final class ExamplePost extends Model
{
    protected $table = 'example_posts';

    protected $guarded = [];
}

final class MediaReferencesTest extends TestCase
{
    private function upload(string $workspace, string $name = 'brief.txt'): array
    {
        return $this->withHeader('Idempotency-Key', 'reference-upload-'.bin2hex(random_bytes(16)))
            ->postJson($this->url($workspace, 'uploads'), ['file' => UploadedFile::fake()->createWithContent($name, 'Shared original bytes')])->assertCreated()->json();
    }

    public function test_batch_resolution_preserves_order_and_duplicates_without_n_plus_one(): void
    {
        $workspace = $this->workspace();
        $a = $this->upload($workspace, 'a.txt');
        $b = $this->upload($workspace, 'b.txt');
        $references = array_map(fn ($i): array => ['assetId' => $i % 2 === 0 ? $b['id'] : $a['id']], range(0, 99));
        DB::enableQueryLog();
        $response = $this->postJson($this->url($workspace, 'references/resolve'), ['references' => $references])->assertOk()->assertJsonCount(100, 'items');
        $queries = DB::getQueryLog();
        DB::disableQueryLog();
        $this->assertLessThanOrEqual(3, count($queries));
        $this->assertSame(array_column($references, 'assetId'), array_column($response->json('items'), 'assetId'));
        $this->get($response->json('items.0.url'))->assertOk();
        $this->assertDatabaseCount('fms_entries', 2);
        $this->assertDatabaseCount('fms_asset_versions', 2);
    }

    public function test_version_must_belong_to_selected_asset_and_workspace(): void
    {
        $workspace = $this->workspace();
        $a = $this->upload($workspace, 'a.txt');
        $b = $this->upload($workspace, 'b.txt');
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => [['assetId' => $a['id'], 'versionId' => $b['versionId']]]])->assertNotFound();
        $this->get($this->url($workspace, 'entries/'.$a['id'].'/versions/'.$b['versionId'].'/content'))->assertNotFound();
        $other = $this->workspace('Other');
        $this->postJson($this->url($other, 'references/resolve'), ['references' => [['assetId' => $a['id']]]])->assertNotFound();
        $this->postJson($this->url($workspace, 'references/resolve'), ['references' => array_fill(0, 121, ['assetId' => $a['id']])])->assertUnprocessable();
    }

    public function test_two_records_share_one_original_and_removing_one_usage_keeps_the_other(): void
    {
        Schema::create('example_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        config()->set('media-studio.usage_owners', ['post' => ExamplePost::class]);
        Gate::define('update', fn (GenericUser $actor, ExamplePost $post): bool => $actor->id === 'member-uuid-001');
        $workspace = $this->workspace();
        $entry = $this->upload($workspace);
        $a = ExamplePost::create(['title' => 'A']);
        $b = ExamplePost::create(['title' => 'B']);
        $references = app(MediaReferences::class);
        $actor = auth()->user();
        $attachment = [['assetId' => $entry['id'], 'slot' => 'hero', 'metadata' => ['alt' => 'Shared asset']]];
        $references->sync($actor, $a, 'cover', $workspace, $attachment);
        $references->sync($actor, $b, 'cover', $workspace, $attachment);
        $this->assertDatabaseCount('fms_usages', 2);
        $references->sync($actor, $a, 'cover', $workspace, []);
        $this->assertDatabaseCount('fms_usages', 1);
        $this->postJson($this->url($workspace, 'entries/'.$entry['id'].'/trash'), ['revision' => 1])->assertConflict();
        $this->assertDatabaseCount('fms_asset_versions', 1);
        $references->sync($actor, $b, 'cover', $workspace, []);
        $this->postJson($this->url($workspace, 'entries/'.$entry['id'].'/trash'), ['revision' => 1])->assertOk();
        $this->assertDatabaseCount('fms_asset_versions', 1);
    }

    public function test_owner_policy_is_enforced_before_replacing_usage_records(): void
    {
        Schema::create('example_posts', function (Blueprint $table): void {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });
        config()->set('media-studio.usage_owners', ['post' => ExamplePost::class]);
        Gate::define('update', fn (): bool => false);
        $workspace = $this->workspace();
        $entry = $this->upload($workspace);
        $owner = ExamplePost::create(['title' => 'Protected']);
        $this->expectException(AuthorizationException::class);
        app(MediaReferences::class)->sync(auth()->user(), $owner, 'cover', $workspace, [['assetId' => $entry['id'], 'slot' => 'hero']]);
    }
}
