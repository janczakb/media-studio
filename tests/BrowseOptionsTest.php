<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Tests;

use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\BrowseFilter;
use UseFlex\MediaStudio\Library\BrowseSorter;

final class BrowseOptionsTest extends TestCase
{
    private function seedEntries(string $workspace): array
    {
        $workspaceId = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        $definitions = [
            ['name' => 'A folder', 'kind' => 'folder', 'size_bytes' => null, 'media_type' => null, 'mime_type' => null, 'updated_at' => null],
            ['name' => 'Bravo.jpg', 'size_bytes' => 300, 'media_type' => 'image', 'mime_type' => 'image/jpeg', 'updated_at' => '2026-11-03 12:00:00'],
            ['name' => 'Alpha.mp4', 'size_bytes' => 900, 'media_type' => 'video', 'mime_type' => 'video/mp4', 'updated_at' => '2026-11-04 23:59:59.999999'],
            ['name' => 'Echo.pdf', 'size_bytes' => 300, 'media_type' => 'application', 'mime_type' => 'application/pdf', 'updated_at' => '2026-11-05 00:00:00'],
            ['name' => 'Charlie.docx', 'size_bytes' => 20, 'media_type' => 'application', 'mime_type' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'updated_at' => '2026-11-02 00:00:00'],
            ['name' => 'Delta.txt', 'size_bytes' => 0, 'media_type' => 'text', 'mime_type' => 'text/plain', 'updated_at' => '2026-11-03 00:00:00'],
        ];
        $items = [];
        foreach ($definitions as $definition) {
            $id = (string) Str::ulid();
            $row = ['kind' => 'file', ...$definition, 'public_id' => $id, 'workspace_id' => $workspaceId,
                'normalized_name' => mb_strtolower($definition['name']), 'sort_group' => ($definition['kind'] ?? 'file') === 'folder' ? 0 : 1,
                'created_at' => '2026-11-01 00:00:00',
            ];
            DB::table('fms_entries')->insert($row);
            $items[] = (object) [...$row, 'id' => DB::table('fms_entries')->where('public_id', $id)->value('id')];
        }

        return $items;
    }

    private function browse(string $workspace, array $options): TestResponse
    {
        return $this->getJson($this->url($workspace).'?'.http_build_query($options));
    }

    public function test_mime_category_patterns_keep_folders_and_exclude_other_files(): void
    {
        $workspace = $this->workspace();
        $this->seedEntries($workspace);
        $page = $this->browse($workspace, ['filters' => ['mimeTypes' => ['image/*']]])->assertOk();
        $this->assertNotEmpty($page->json('items'));
        foreach ($page->json('items') as $item) {
            $this->assertTrue($item['kind'] === 'folder' || str_starts_with($item['mimeType'], 'image/'));
        }
        $this->browse($workspace, ['filters' => ['mimeTypes' => ['image/%']]])->assertUnprocessable();
    }

    public function test_every_sort_uses_deterministic_bidirectional_keyset_pages_including_null_source_values(): void
    {
        $workspace = $this->workspace();
        $this->seedEntries($workspace);
        foreach (['name' => 'normalized_name', 'updated' => 'browse_updated', 'size' => 'browse_size', 'type' => 'browse_type'] as $sort => $column) {
            foreach (['asc', 'desc'] as $direction) {
                $query = DB::table('fms_entries');
                if ($sort === 'name') {
                    $query->orderBy('sort_group', $direction);
                }
                $expected = $query->orderBy($column, $direction)->orderBy('id', $direction)->pluck('public_id')->all();
                $seen = [];
                $cursor = null;
                $first = null;
                do {
                    $page = $this->browse($workspace, ['sort' => $sort, 'direction' => $direction, 'limit' => 2, 'cursor' => $cursor])->assertOk();
                    $first ??= $page->json('items');
                    $seen = [...$seen, ...array_column($page->json('items'), 'id')];
                    if (count($seen) === 4) {
                        $back = $this->browse($workspace, ['sort' => $sort, 'direction' => $direction, 'limit' => 2, 'cursor' => $page->json('previousCursor')])->assertOk();
                        $this->assertSame($first, $back->json('items'));
                    }
                    $cursor = $page->json('nextCursor');
                } while ($cursor !== null && count($seen) < 12);
                $this->assertSame($expected, $seen, $sort.' '.$direction);
            }
        }
    }

    public function test_exact_mime_scope_keeps_folders_and_binds_the_cursor(): void
    {
        $workspace = $this->workspace();
        $this->seedEntries($workspace);

        $filters = ['mimeTypes' => ['application/pdf']];
        $first = $this->browse($workspace, ['filters' => $filters, 'limit' => 1])->assertOk()
            ->assertJsonPath('items.0.kind', 'folder');
        $cursor = $first->json('nextCursor');
        $this->assertNotNull($cursor);
        $this->browse($workspace, ['filters' => $filters, 'limit' => 1, 'cursor' => $cursor])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Echo.pdf');
        $this->browse($workspace, ['filters' => ['mimeTypes' => ['image/jpeg']], 'limit' => 1, 'cursor' => $cursor])
            ->assertUnprocessable();
        $this->browse($workspace, ['filters' => $filters, 'search' => 'Bravo'])->assertOk()->assertJsonCount(0, 'items');
    }

    public function test_filters_combine_inclusive_utc_dates_sizes_and_types_and_bind_cursor_scope(): void
    {
        $workspace = $this->workspace();
        $items = $this->seedEntries($workspace);
        $this->browse($workspace, ['filters' => ['dateFrom' => '2026-11-03', 'dateTo' => '2026-11-04']])->assertOk()
            ->assertJsonCount(3, 'items');
        $this->browse($workspace, ['filters' => ['minBytes' => 300, 'maxBytes' => 900, 'type' => 'image']])->assertOk()
            ->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $items[1]->public_id);
        $this->browse($workspace, ['filters' => ['type' => 'document']])->assertOk()->assertJsonCount(2, 'items');
        $this->browse($workspace, ['filters' => ['maxBytes' => 0]])->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.name', 'Delta.txt');
        $page = $this->browse($workspace, ['sort' => 'size', 'limit' => 1])->assertOk();
        $cursor = $page->json('nextCursor');
        foreach ([
            ['sort' => 'size', 'direction' => 'desc'],
            ['sort' => 'name'],
            ['sort' => 'size', 'filters' => ['minBytes' => 0]],
        ] as $changed) {
            $this->browse($workspace, [...$changed, 'cursor' => $cursor])->assertUnprocessable();
        }
        $this->browse($workspace, ['filters' => ['dateFrom' => '2026-11-05', 'dateTo' => '2026-11-01']])->assertUnprocessable();
        $this->browse($workspace, ['filters' => ['dateTo' => 'invalid']])->assertUnprocessable();
        $this->browse($workspace, ['filters' => ['minBytes' => 10, 'maxBytes' => 1]])->assertUnprocessable();
        $this->browse($workspace, ['filters' => ['disk' => 'secret']])->assertUnprocessable();
        $this->browse($workspace, ['sort' => 'size_bytes;DROP TABLE fms_entries'])->assertUnprocessable();
    }

    public function test_preview_neighbors_keep_filter_and_sort_order_for_each_direction(): void
    {
        $workspace = $this->workspace();
        $items = $this->seedEntries($workspace);
        $anchor = $items[1]->public_id;
        foreach (['asc', 'desc'] as $direction) {
            $url = $this->url($workspace, 'entries/'.$anchor.'/neighbors');
            $response = $this->getJson($url.'?'.http_build_query(['direction' => 'next', 'sort' => 'size', 'sortDirection' => $direction]))->assertOk();
            $expected = DB::table('fms_entries')->where('kind', 'file')
                ->where(fn ($q) => $q->where('browse_size', $direction === 'asc' ? '>' : '<', 300)
                    ->orWhere(fn ($tie) => $tie->where('browse_size', 300)->where('id', $direction === 'asc' ? '>' : '<', $items[1]->id)))
                ->orderBy('browse_size', $direction)->orderBy('id', $direction)->pluck('public_id')->all();
            $this->assertSame($expected, array_column($response->json('items'), 'id'));
        }
        $this->getJson($this->url($workspace, 'entries/'.$anchor.'/neighbors').'?'.http_build_query([
            'direction' => 'next', 'sort' => 'size', 'filters' => ['type' => 'pdf'],
        ]))->assertNotFound();
    }

    public function test_sort_indexes_avoid_temporary_sort_and_unbounded_queries(): void
    {
        $workspace = $this->workspace();
        $this->seedEntries($workspace);
        $workspaceId = DB::table('fms_workspaces')->value('id');
        foreach (['updated', 'size', 'type'] as $sort) {
            $plan = DB::select('EXPLAIN QUERY PLAN SELECT public_id FROM fms_entries WHERE workspace_id=? AND parent_scope=0 AND trashed_at IS NULL ORDER BY browse_'.$sort.',id LIMIT 61', [$workspaceId]);
            $detail = implode(' ', array_column($plan, 'detail'));
            $this->assertStringContainsString('fms_browse_'.$sort, $detail);
            $this->assertStringNotContainsString('TEMP B-TREE', $detail);
        }
    }

    public function test_registered_filters_stay_inside_workspace_and_share_the_preview_scope(): void
    {
        $workspace = $this->workspace();
        $items = $this->seedEntries($workspace);
        $other = $this->workspace('Other tenant');
        $this->seedEntries($other);
        config(['media-studio.browse.filters' => ['documents' => DocumentGroupFilter::class], 'media-studio.browse.sorters' => ['recently-added' => AddedEntrySorter::class]]);
        $page = $this->browse($workspace, ['customFilters' => ['documents' => 'enabled'], 'sort' => 'recently-added', 'direction' => 'desc', 'limit' => 1])
            ->assertOk()->assertJsonCount(1, 'items')->assertJsonPath('items.0.id', $items[5]->public_id);
        $cursor = $page->json('nextCursor');
        $this->browse($workspace, ['customFilters' => ['documents' => 'enabled'], 'sort' => 'recently-added', 'direction' => 'desc', 'cursor' => $cursor])
            ->assertOk()->assertJsonCount(2, 'items');
        $this->browse($workspace, ['sort' => 'recently-added', 'direction' => 'desc', 'cursor' => $cursor])->assertUnprocessable();
        $this->browse($workspace, ['customFilters' => ['unknown' => '1']])->assertUnprocessable();
        $this->browse($workspace, ['customFilters' => ['documents' => 'unsupported']])->assertUnprocessable();
        $this->getJson($this->url($workspace, 'entries/'.$items[4]->public_id.'/neighbors').'?'.http_build_query([
            'direction' => 'next', 'sortDirection' => 'asc', 'sort' => 'recently-added', 'customFilters' => ['documents' => 'enabled'],
        ]))->assertOk()->assertJsonPath('items.0.id', $items[5]->public_id)->assertJsonCount(1, 'items');
    }

    public function test_cursor_rejects_a_reconfigured_custom_sorter_before_reading_old_keyset_parameters(): void
    {
        $workspace = $this->workspace();
        $this->seedEntries($workspace);
        config(['media-studio.browse.sorters' => ['custom-order' => AddedEntrySorter::class]]);
        $page = $this->browse($workspace, ['sort' => 'custom-order', 'limit' => 1])->assertOk();
        $cursor = $page->json('nextCursor');
        $this->assertNotNull($cursor);

        config(['media-studio.browse.sorters' => ['custom-order' => UpdatedEntrySorter::class]]);
        $this->browse($workspace, ['sort' => 'custom-order', 'limit' => 1, 'cursor' => $cursor])->assertUnprocessable();

        config(['media-studio.browse.sorters' => ['custom-order' => AddedEntrySorter::class], 'media-studio.browse.revision' => 2]);
        $this->browse($workspace, ['sort' => 'custom-order', 'limit' => 1, 'cursor' => $cursor])->assertUnprocessable();
    }

    public function test_utc_calendar_filter_respects_the_storage_timezone_and_daylight_saving_change(): void
    {
        $workspace = $this->workspace();
        $workspaceId = DB::table('fms_workspaces')->where('public_id', $workspace)->value('id');
        config(['app.timezone' => 'Europe/Warsaw']);
        $times = [
            'Before.txt' => '2026-03-29 00:59:59',
            'Start.txt' => '2026-03-29 01:00:00',
            'Last.txt' => '2026-03-30 01:59:59.999999',
            'After.txt' => '2026-03-30 02:00:00',
        ];
        foreach ($times as $name => $timestamp) {
            DB::table('fms_entries')->insert([
                'public_id' => (string) Str::ulid(), 'workspace_id' => $workspaceId,
                'name' => $name, 'normalized_name' => strtolower($name), 'kind' => 'file', 'sort_group' => 1,
                'media_type' => 'text', 'mime_type' => 'text/plain', 'size_bytes' => 1,
                'created_at' => $timestamp, 'updated_at' => $timestamp,
            ]);
        }

        $response = $this->browse($workspace, ['filters' => ['dateFrom' => '2026-03-29', 'dateTo' => '2026-03-29']])->assertOk();
        $this->assertSame(['Last.txt', 'Start.txt'], array_column($response->json('items'), 'name'));
    }
}

final class DocumentGroupFilter implements BrowseFilter
{
    public function apply(Builder $predicate, string $value, object $workspace, Principal $principal): void
    {
        abort_unless($value === 'enabled', 422);
        // This OR must remain nested below Studio's workspace and active-entry constraints.
        $predicate->where('browse_type', 'document')->orWhere('browse_type', 'pdf');
    }
}

final class AddedEntrySorter implements BrowseSorter
{
    public function column(): string
    {
        return 'id';
    }
}

final class UpdatedEntrySorter implements BrowseSorter
{
    public function column(): string
    {
        return 'browse_updated';
    }
}
