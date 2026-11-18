<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Closure;
use Filament\Forms\Components\Select;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class FolderSelect
{
    public const ROOT = '__studio_root';

    /** @param Closure():array{Principal,string} $scope */
    public static function make(Closure $scope): Select
    {
        $rootLabel = __('media-studio::actions.root');

        return Select::make('parent')
            ->label(__('media-studio::actions.destination'))
            ->options([self::ROOT => $rootLabel])
            ->searchable()
            ->optionsLimit(120)
            ->getSearchResultsUsing(function (string $search) use ($scope, $rootLabel): array {
                [$principal, $workspace] = $scope();
                $prefix = mb_substr(mb_strtolower(trim($search), 'UTF-8'), 0, 191);
                $rows = self::folders($principal, $workspace)
                    ->whereRaw("normalized_name LIKE ? ESCAPE '!'", [str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $prefix).'%'])
                    ->orderBy('normalized_name')->orderBy('id')->limit(119)
                    ->pluck('name', 'public_id')->all();

                return [self::ROOT => $rootLabel] + $rows;
            })
            ->getOptionLabelUsing(function (mixed $value) use ($scope, $rootLabel): ?string {
                if ($value === self::ROOT) {
                    return $rootLabel;
                }
                if (! is_string($value) || ! Str::isUlid($value)) {
                    return null;
                }
                [$principal, $workspace] = $scope();

                return self::folders($principal, $workspace)->where('public_id', strtoupper($value))->value('name');
            })
            ->required();
    }

    public static function parent(mixed $value): ?string
    {
        if ($value === self::ROOT) {
            return null;
        }
        abort_unless(is_string($value) && Str::isUlid($value), 422, 'Select a Studio folder or the workspace root.');

        return strtoupper($value);
    }

    private static function folders(Principal $principal, string $workspace): Builder
    {
        $scope = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);

        return DB::table('fms_entries')->where('workspace_id', $scope->id)->whereNull('media_type')
            ->whereNull('trashed_at')->where('sort_group', 0)->where('kind', 'folder')->where('source_kind', 'managed');
    }
}
