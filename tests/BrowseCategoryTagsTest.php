<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Auth\GenericUser;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Integrations\Spatie\SourceMount;
use UseFlex\MediaStudio\Library\EntryTags;

final class BrowseCategoryTagsTest extends TestCase
{
    private function entry(string $workspace, string $name, string $mime = 'application/octet-stream', ?string $parent = null): object
    {
        $wid = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $parentId = $parent === null ? null : DB::table('fms_entries')->where('public_id', $parent)->value('id');
        $id = DB::table('fms_entries')->insertGetId([
            'public_id' => (string) Str::ulid(), 'workspace_id' => $wid,
            'parent_id' => $parentId, 'parent_scope' => $parentId ?? 0,
            'name' => $name, 'normalized_name' => mb_strtolower($name), 'kind' => 'file', 'sort_group' => 1,
            'media_type' => explode('/', $mime)[0], 'mime_type' => $mime, 'size_bytes' => 100,
            'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('fms_entries')->where('id', $id)->first();
    }

    private function browse(string $workspace, array $params = [])
    {
        return $this->getJson($this->url($workspace).'?'.http_build_query($params));
    }

    private function assign(string $workspace, object $entry, array $tags, int $revision = 1)
    {
        return $this->patchJson($this->url($workspace, 'entries/'.$entry->public_id.'/tags'), ['revision' => $revision, 'tags' => $tags]);
    }

    public function test_types_and_extensions_are_indexed_and_follow_rename_without_reindexing(): void
    {
        $w = $this->workspace();
        foreach ([['script.PHP', 'code'], ['budget.csv', 'spreadsheet'], ['pitch.pptx', 'presentation'], ['photo.AVIF', 'image'], ['brochure.pdf', 'document'], ['archive.tar.gz', 'archive'], ['font.woff2', 'font'], ['film.mp4', 'video'], ['voice.flac', 'audio']] as [$name, $category]) {
            $entry = $this->entry($w, $name);
            $this->assertSame($category, $entry->browse_category);
            $this->browse($w, ['filters' => ['types' => [$category]]])->assertOk()->assertJsonPath('items.0.id', $entry->public_id)->assertJsonCount(1, 'items');
        }
        $response = $this->browse($w, ['filters' => ['types' => ['code', 'document'], 'extensions' => ['PHP', 'pdf']]])->assertOk();
        $this->assertSame(['brochure.pdf', 'script.PHP'], array_column($response->json('items'), 'name'));
        $entry = DB::table('fms_entries')->where('name', 'script.PHP')->first();
        $this->patchJson($this->url($w, 'entries/'.$entry->public_id), ['revision' => 1, 'name' => 'script.txt'])->assertOk();
        $this->browse($w, ['filters' => ['extensions' => ['php']]])->assertOk()->assertJsonCount(0, 'items');
        $this->browse($w, ['filters' => ['extensions' => ['txt']]])->assertOk()->assertJsonPath('items.0.name', 'script.txt');
        foreach (['category', 'extension'] as $column) {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT id FROM fms_entries WHERE workspace_id=1 AND parent_scope=0 AND trashed_at IS NULL AND browse_'.$column.'=? ORDER BY id LIMIT 61', ['php']);
            $this->assertStringContainsString('fms_browse_'.$column, implode(' ', array_column($plan, 'detail')));
        }
    }

    public function test_tag_assignment_locale_any_all_revision_and_scope_are_enforced(): void
    {
        $w = $this->workspace();
        $a = $this->entry($w, 'a.jpg');
        $b = $this->entry($w, 'b.jpg');
        $tags = [['name' => 'Hotel', 'locale' => 'en'], ['name' => 'Architecture', 'locale' => 'en'], ['name' => 'Hotel PL', 'locale' => 'pl']];
        $assigned = $this->assign($w, $a, $tags)->assertOk()->assertJsonPath('revision', 2)->json('tags');
        $this->assign($w, $a, [])->assertConflict();
        $hotel = array_values(array_filter($assigned, fn ($tag) => $tag['label'] === 'Hotel'))[0]['id'];
        $architecture = array_values(array_filter($assigned, fn ($tag) => $tag['label'] === 'Architecture'))[0]['id'];
        $this->assign($w, $b, [['id' => $hotel]])->assertOk();
        $this->browse($w, ['filters' => ['tagIds' => [$hotel, $architecture]]])->assertOk()->assertJsonCount(2, 'items');
        $this->browse($w, ['filters' => ['tagIds' => [$hotel, $architecture], 'tagMatch' => 'all']])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $a->public_id);
        $this->browse($w, ['filters' => ['tagLocale' => 'pl']])->assertOk()->assertJsonCount(1, 'items');
        $this->browse($w, ['filters' => ['tagIds' => [$hotel], 'tagLocale' => 'pl']])->assertOk()->assertJsonCount(0, 'items');
        $foreign = $this->workspace('Other');
        $foreignEntry = $this->entry($foreign, 'secret.jpg');
        $tag = $this->assign($foreign, $foreignEntry, [['name' => 'Secret', 'locale' => 'en']])->assertOk()->json('tags.0.id');
        $this->assign($w, $b, [['id' => $tag]], 2)->assertUnprocessable();
        $this->assertSame(1, DB::table('fms_entry_tags')->where('entry_id', $b->id)->count());
        $this->browse($w, ['filters' => ['tagIds' => [$tag]]])->assertOk()->assertJsonCount(0, 'items');
        $this->actingAs(new GenericUser(['id' => 'outsider']));
        $this->getJson($this->url($w, 'filter-options?kind=tags'))->assertNotFound();
        $this->assign($w, $a, [], 2)->assertNotFound();
    }

    public function test_choices_use_bounded_keysets_and_cursors_cannot_cross_workspace_locale_or_kind(): void
    {
        $w = $this->workspace();
        $entry = $this->entry($w, 'photo.jpg');
        $this->assign($w, $entry, [['name' => 'Alpha', 'locale' => 'en'], ['name' => 'Beta', 'locale' => 'en'], ['name' => 'Bravo', 'locale' => 'pl']])->assertOk();
        $first = $this->getJson($this->url($w, 'filter-options?kind=tags&limit=1'))->assertOk()->assertJsonPath('items.0.label', 'Alpha')->json('nextCursor');
        $this->getJson($this->url($w, 'filter-options?').http_build_query(['kind' => 'tags', 'cursor' => $first, 'limit' => 1]))->assertOk()->assertJsonPath('items.0.label', 'Beta');
        $this->getJson($this->url($w, 'filter-options?kind=tags&locale=pl'))->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.label', 'Bravo');
        $this->getJson($this->url($w, 'filter-options?kind=tags&search=B'))->assertOk()->assertJsonCount(2, 'items');
        $other = $this->workspace('Other');
        foreach ([[$other, ['kind' => 'tags']], [$w, ['kind' => 'extensions']], [$w, ['kind' => 'tags', 'locale' => 'pl']]] as [$workspace, $params]) {
            $this->getJson($this->url($workspace, 'filter-options?').http_build_query([...$params, 'cursor' => $first]))->assertUnprocessable();
        }
        $this->getJson($this->url($w, 'filter-options?kind=extensions&search=jp'))->assertOk()->assertJsonCount(2, 'items')->assertJsonPath('items.0.id', 'jpeg');
        $this->getJson($this->url($w, 'filter-options?kind=tagLocales'))->assertOk()->assertJsonCount(2, 'items');
        $page = $this->browse($w, ['limit' => 1, 'filters' => ['types' => ['image']]])->assertOk();
        $this->browse($w, ['filters' => ['extensions' => array_fill(0, 33, 'jpg')]])->assertUnprocessable();
        $this->assign($w, $entry, array_fill(0, 33, ['name' => 'Too many', 'locale' => 'en']), 2)->assertUnprocessable();
        $this->assertNotEmpty($page->json('items'));
    }

    public function test_revoked_external_source_tags_never_escape_through_suggestions(): void
    {
        Schema::create('source_media', function ($table): void {
            $table->id();
            $table->boolean('allowed')->default(true);
        });
        SourceChoiceMedia::query()->create(['id' => 42, 'allowed' => true]);
        $w = $this->workspace();
        $entry = $this->entry($w, 'private.jpg');
        $workspace = DB::table('fms_workspaces')->where('public_id', $w)->first();
        $media = SourceChoiceMedia::query()->first();
        config(['media-studio.integrations.spatie.mounts.choice-test' => [
            'media_model' => SourceChoiceMedia::class,
            'scope' => fn (Builder $query) => $query->where('allowed', true),
        ]]);
        $mount = SourceMount::find('choice-test');
        DB::table('fms_entries')->where('id', $entry->id)->update(['source_kind' => 'spatie']);
        DB::table('fms_spatie_sources')->insert(['workspace_id' => $workspace->id, 'mount_key' => 'choice-test', 'media_key' => '42', 'source_key' => $mount->identity($media), 'source_revision' => 'x', 'entry_id' => $entry->id, 'created_at' => now(), 'updated_at' => now()]);
        app(EntryTags::class)->project($workspace, $entry->id, [['name' => 'Private label', 'locale' => 'en']]);
        $this->getJson($this->url($w, 'filter-options?kind=tags'))->assertOk()->assertJsonPath('items.0.label', 'Private label');
        SourceChoiceMedia::query()->whereKey(42)->update(['allowed' => false]);
        $this->getJson($this->url($w, 'filter-options?kind=tags'))->assertOk()->assertJsonCount(0, 'items');
        $this->assign($w, $entry, [])->assertForbidden();
    }
}

final class SourceChoiceMedia extends Model
{
    protected $table = 'source_media';

    protected $guarded = [];

    public $timestamps = false;
}
