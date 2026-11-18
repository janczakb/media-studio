<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

final class AiLabelReviewTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        foreach (glob(__DIR__.'/../packages/laravel/database/intelligence-migrations/*.php') as $path) {
            (require $path)->up();
        }
        config(['media-studio.intelligence.enabled' => true]);
    }

    private function label(string $workspace, string $name = 'Cat'): array
    {
        $public = (string) Str::ulid();
        $version = (string) Str::ulid();
        $analysis = hash('sha256', $public);
        $entry = DB::table('fms_entries')->insertGetId(['workspace_id' => DB::table('fms_workspaces')->where('public_id', $workspace)->value('id'), 'public_id' => $public, 'kind' => 'file', 'sort_group' => 1, 'name' => 'Photo '.$public, 'normalized_name' => 'photo '.$public, 'media_type' => 'image', 'source_kind' => 'managed', 'current_version_id' => $version, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('fms_image_analyses')->insert(['id' => $analysis, 'entry_id' => $entry, 'version_id' => $version, 'provider' => 'google_vision', 'description' => 'A cat', 'created_at' => now()]);
        $id = DB::table('fms_ai_labels')->insertGetId(['analysis_id' => $analysis, 'name' => $name, 'normalized_name' => strtolower($name), 'score' => .9]);

        return [$id, $entry];
    }

    public function test_review_is_version_checked_permission_gated_and_separate_from_manual_tags(): void
    {
        $workspace = $this->workspace();
        [$label, $entry] = $this->label($workspace);
        $url = $this->url($workspace, 'ai/labels');
        $this->getJson($url)->assertOk()->assertJsonPath('items.0.name', 'Cat')->assertJsonPath('canReview', true);
        $this->patchJson($url.'/'.$label, ['state' => 'accepted', 'revision' => 1])->assertOk()->assertJsonPath('revision', 2);
        $this->assertSame('accepted', DB::table('fms_ai_labels')->where('id', $label)->value('review_state'));
        $this->patchJson($url.'/'.$label, ['state' => 'rejected', 'revision' => 1])->assertConflict();
        Gate::define('studio.ai.review', static fn () => false);
        $this->patchJson($url.'/'.$label, ['state' => 'rejected', 'revision' => 2])->assertForbidden();
        DB::table('fms_entries')->where('id', $entry)->update(['current_version_id' => (string) Str::ulid()]);
        $this->getJson($url.'?state=all')->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_label_scope_and_cursor_do_not_leak_other_workspaces(): void
    {
        $workspace = $this->workspace();
        $other = $this->workspace('Other');
        for ($i = 0; $i < 61; $i++) {
            $this->label($workspace, 'Label '.$i);
        }
        [$foreign] = $this->label($other, 'Private');
        $page = $this->getJson($this->url($workspace, 'ai/labels'))->assertOk()->assertJsonCount(60, 'items')->assertJsonPath('hasMore', true)->json();
        $this->getJson($this->url($workspace, 'ai/labels').'?cursor='.urlencode($page['nextCursor']))->assertOk()->assertJsonCount(1, 'items');
        $this->getJson($this->url($other, 'ai/labels').'?cursor='.urlencode($page['nextCursor']))->assertUnprocessable();
        $this->patchJson($this->url($workspace, 'ai/labels/'.$foreign), ['state' => 'accepted', 'revision' => 1])->assertNotFound();
        $this->assertSame('pending', DB::table('fms_ai_labels')->where('id', $foreign)->value('review_state'));
    }
}
