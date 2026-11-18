<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use LogicException;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Identity\Principal;

final readonly class BrowseOptions
{
    private function __construct(public array $filters, public string $sort, public string $direction, public array $customFilters = []) {}

    public static function from(Request $request, string $view, bool $neighbors = false): self
    {
        $direction = $neighbors ? 'sortDirection' : 'direction';
        $data = $request->validate([
            'sort' => ['nullable', 'string', 'regex:/\\A[a-z][a-z0-9_-]{0,63}\\z/'],
            'customFilters' => ['nullable', 'array', 'max:8'],
            'customFilters.*' => ['required', 'string', 'max:191'],
            $direction => ['nullable', 'in:asc,desc'],
            'filters' => ['nullable', 'array:dateFrom,dateTo,minBytes,maxBytes,type,types,extensions,mimeTypes,tagIds,tagMatch,tagLocale,uploaderIds'],
            'filters.dateFrom' => ['nullable', 'date_format:Y-m-d'],
            'filters.dateTo' => ['nullable', 'date_format:Y-m-d'],
            'filters.minBytes' => ['nullable', 'integer', 'min:0'],
            'filters.maxBytes' => ['nullable', 'integer', 'min:0'],
            'filters.types' => ['nullable', 'array', 'max:10'],
            'filters.types.*' => ['required', 'string', 'in:code,document,spreadsheet,presentation,image,video,audio,archive,font,other'],
            'filters.extensions' => ['nullable', 'array', 'max:32'],
            'filters.mimeTypes' => ['nullable', 'array', 'max:32'],
            'filters.mimeTypes.*' => ['required', 'string', 'regex:/\A[a-z0-9][a-z0-9.+_-]{0,63}\/(?:[a-z0-9][a-z0-9.+_-]{0,95}|\*)\z/'],
            'filters.extensions.*' => ['required', 'string', 'regex:/\\A[a-zA-Z0-9][a-zA-Z0-9+_-]{0,31}\\z/'],
            'filters.tagIds' => ['nullable', 'array', 'max:32'],
            'filters.tagIds.*' => ['required', 'ulid'],
            'filters.uploaderIds' => ['nullable', 'array', 'max:32'],
            'filters.uploaderIds.*' => ['required', 'integer', 'min:1'],
            'filters.tagMatch' => ['nullable', 'in:any,all'],
            'filters.tagLocale' => ['nullable', 'string', 'regex:/\\A[a-zA-Z]{2,3}(?:[-_][a-zA-Z0-9]{2,8})?\\z/'],
            'filters.type' => ['nullable', 'in:image,video,audio,pdf,document,other'],
        ]);
        $filters = array_filter($data['filters'] ?? [], static fn ($value): bool => $value !== null);
        foreach (['minBytes', 'maxBytes'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = (int) $filters[$key];
            }
        }
        foreach (['types', 'extensions', 'mimeTypes', 'tagIds', 'uploaderIds'] as $key) {
            if (isset($filters[$key])) {
                $filters[$key] = array_values(array_unique(array_map($key === 'extensions' ? strtolower(...) : strval(...), $filters[$key])));
                sort($filters[$key], SORT_STRING);
                if ($filters[$key] === []) {
                    unset($filters[$key]);
                }
            }
        }
        if (! isset($filters['tagIds'])) {
            unset($filters['tagMatch']);
        }
        ksort($filters);
        abort_if(isset($filters['dateFrom'], $filters['dateTo']) && $filters['dateFrom'] > $filters['dateTo'], 422, 'Start date must not be after end date.');
        abort_if(isset($filters['minBytes'], $filters['maxBytes']) && $filters['minBytes'] > $filters['maxBytes'], 422, 'Minimum size must not exceed maximum size.');
        $customFilters = $data['customFilters'] ?? [];
        ksort($customFilters);
        foreach ($customFilters as $key => $value) {
            abort_unless(is_string($key) && preg_match('/\\A[a-z][a-z0-9_-]{0,63}\\z/', $key)
                && array_key_exists($key, (array) config('media-studio.browse.filters', [])), 422, 'Unknown custom filter.');
        }
        $sort = $data['sort'] ?? 'name';
        abort_unless(in_array($sort, ['name', 'updated', 'size', 'type'], true)
            || array_key_exists($sort, (array) config('media-studio.browse.sorters', [])), 422, 'Unknown sorter.');
        $order = $data[$direction] ?? 'asc';
        abort_if(! in_array($view, ['files', 'tagged'], true) && ($filters !== [] || $customFilters !== [] || $sort !== 'name' || $order !== 'asc'), 422, 'These browse options apply to Files.');

        return new self($filters, $sort, $order, $customFilters);
    }

    public function column(): string
    {
        return match ($this->sort) {
            'updated' => 'browse_updated',
            'size' => 'browse_size',
            'type' => 'browse_type',
            'name' => 'normalized_name',
            default => $this->customColumn(),
        };
    }

    public function order(Builder $query): void
    {
        if ($this->sort === 'name') {
            $query->orderBy('sort_group', $this->direction);
        }
        $query->orderBy($this->column(), $this->direction)->orderBy('id', $this->direction);
    }

    public function apply(Builder $query, object $workspace, Principal $principal): void
    {
        foreach ($this->customFilters as $key => $value) {
            $type = config('media-studio.browse.filters.'.$key);
            $filter = is_string($type) ? app($type) : null;
            if (! $filter instanceof BrowseFilter) {
                throw new LogicException('Registered browse filters must implement BrowseFilter.');
            }
            $predicate = $query->newQuery()->from('fms_entries');
            $filter->apply($predicate, $value, $workspace, $principal);
            if ($predicate->from !== 'fms_entries' || $predicate->columns !== null || $predicate->joins !== null
                || $predicate->orders !== null || $predicate->unions !== null || $predicate->groups !== null
                || $predicate->havings !== null || $predicate->limit !== null || $predicate->offset !== null
                || $predicate->aggregate !== null || $predicate->lock !== null || $predicate->distinct) {
                throw new LogicException('A custom browse filter must only add WHERE conditions.');
            }
            $query->addNestedWhereQuery($predicate);
        }
        $filters = $this->filters;
        if (isset($filters['uploaderIds'])) {
            $actors = DB::table('fms_memberships')->where('workspace_id', $workspace->id)
                ->whereIn('id', $filters['uploaderIds'])->select('principal_key');
            app(StudioPermissions::class)->authorize(request()->user(), 'members.view', $workspace);
            $query->where(fn (Builder $uploader) => $uploader->whereIn('uploader_key', $actors)
                ->orWhere(fn (Builder $legacy) => $legacy->whereNull('uploader_key')
                    ->whereExists(fn (Builder $uploads) => $uploads->selectRaw('1')->from('fms_activity')
                        ->where('workspace_id', $workspace->id)->where('event', 'uploaded')
                        ->whereColumn('entry_public_id', 'fms_entries.public_id')->whereIn('actor_key', $actors))));
        }
        if (isset($filters['dateFrom'])) {
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateFrom'], 'UTC')
                ->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
            $query->where('browse_updated', '>=', $from);
        }
        if (isset($filters['dateTo'])) {
            $after = CarbonImmutable::createFromFormat('!Y-m-d', $filters['dateTo'], 'UTC')->addDay()
                ->setTimezone((string) config('app.timezone', 'UTC'))->format('Y-m-d H:i:s');
            $query->where('browse_updated', '<', $after);
        }
        if (isset($filters['minBytes']) || isset($filters['maxBytes'])) {
            $query->where('kind', 'file');
        }
        if (isset($filters['minBytes'])) {
            $query->where('browse_size', '>=', $filters['minBytes']);
        }
        if (isset($filters['maxBytes'])) {
            $query->where('browse_size', '<=', $filters['maxBytes']);
        }
        if (isset($filters['types'])) {
            $query->whereIn('browse_category', $filters['types']);
        }
        if (isset($filters['mimeTypes'])) {
            $query->where(fn (Builder $scope) => $scope->where('kind', 'folder')
                ->orWhere(fn (Builder $files) => $files->where('kind', 'file')->where(function (Builder $mimes) use ($filters): void {
                    foreach ($filters['mimeTypes'] as $mime) {
                        if (str_ends_with($mime, '/*')) {
                            $mimes->orWhereRaw("mime_type LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], substr($mime, 0, -1)).'%']);
                        } else {
                            $mimes->orWhere('mime_type', $mime);
                        }
                    }
                })));
        }
        if (isset($filters['extensions'])) {
            $query->where('kind', 'file')->whereIn('browse_extension', $filters['extensions']);
        }
        if (isset($filters['tagIds'])) {
            $tags = DB::table('fms_tags')->where('workspace_id', $workspace->id)->whereIn('public_id', $filters['tagIds']);
            if (isset($filters['tagLocale'])) {
                $tags->where('locale', $filters['tagLocale']);
            }
            $ids = $tags->pluck('id')->all();
            if (count($ids) !== count($filters['tagIds'])) {
                $query->whereRaw('1 = 0');
            } elseif (($filters['tagMatch'] ?? 'any') === 'all') {
                foreach ($ids as $id) {
                    $query->whereIn('fms_entries.id', fn (Builder $tag) => $tag->select('entry_id')->from('fms_entry_tags')->where('tag_id', $id));
                }
            } else {
                $query->whereIn('fms_entries.id', fn (Builder $tag) => $tag->select('entry_id')->from('fms_entry_tags')->whereIn('tag_id', $ids));
            }
        } elseif (isset($filters['tagLocale'])) {
            $query->whereExists(fn (Builder $tag) => $tag->selectRaw('1')->from('fms_entry_tags')->join('fms_tags', 'fms_tags.id', '=', 'fms_entry_tags.tag_id')->whereColumn('entry_id', 'fms_entries.id')->where('fms_tags.workspace_id', $workspace->id)->where('fms_tags.locale', $filters['tagLocale']));
        }
        if (isset($filters['type'])) {
            $query->where('browse_type', $filters['type']);
        }
    }

    private function customColumn(): string
    {
        $type = config('media-studio.browse.sorters.'.$this->sort);
        $sorter = is_string($type) ? app($type) : null;
        if (! $sorter instanceof BrowseSorter) {
            throw new LogicException('Registered browse sorters must implement BrowseSorter.');
        }
        $column = $sorter->column();
        if (! preg_match('/\\A[a-z][a-z0-9_]{0,63}\\z/', $column)) {
            throw new LogicException('A custom sorter must use one indexed non-null entry column.');
        }

        return $column;
    }

    /** @return array{string, string, array, array, string, int} */
    public function scope(): array
    {
        $revision = config('media-studio.browse.revision', 1);
        if (! is_int($revision) || $revision < 1) {
            throw new LogicException('The browse configuration revision must be a positive integer.');
        }

        return [$this->sort, $this->direction, $this->filters, $this->customFilters, $this->column(), $revision];
    }
}
