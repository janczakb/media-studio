<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Guardian;

use Filament\Forms\Components\CheckboxList;
use Filament\Forms\Components\Toggle;
use Filament\Schemas\Components\Component;
use Filament\Schemas\Components\Section;
use Filament\Schemas\Components\Tabs;
use Filament\Schemas\Components\Tabs\Tab;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Database\Eloquent\Model;
use UseFlex\MediaStudio\Access\PermissionDefinitions;
use UseFlex\MediaStudio\Access\StudioPermissions;

/** Opt-in transformation of Guardian's role schema using public Filament APIs. */
final class GroupedRolePermissions
{
    /**
     * @param  array<Component>  $schema
     * @return array<Component>
     */
    public static function apply(array $schema): array
    {
        if (config('media-studio.permissions.editors.guardian', true) !== true) {
            return $schema;
        }

        foreach ($schema as $component) {
            if (! $component instanceof Tabs) {
                continue;
            }
            $tabs = $component->getDefaultChildComponents();
            if (! is_array($tabs)) {
                continue;
            }
            foreach ($tabs as $tab) {
                if (! $tab instanceof Tab) {
                    continue;
                }
                foreach (self::fields($tab->getDefaultChildComponents()) as $field) {
                    if ($field->getName() !== 'custom_permissions') {
                        continue;
                    }
                    $options = $field->getOptions();
                    $studio = array_intersect_key($options, app(PermissionDefinitions::class)->all());
                    if ($studio === []) {
                        return $schema;
                    }
                    $other = array_diff_key($options, $studio);
                    $field->options($other)->in(array_keys($other))
                        ->afterStateHydrated(static function (CheckboxList $component, ?array $state) use ($other): void {
                            $component->state(array_values(array_intersect($state ?? [], array_keys($other))));
                        });
                    $tab->badge(count($other))->visible($other !== []);
                    $tabs[] = self::studioTab($studio);
                    $component->schema($tabs);
                    self::configureSelectAll($schema);

                    return $schema;
                }
            }
        }

        return $schema;
    }

    /** @param array<string, string> $options */
    private static function studioTab(array $options): Tab
    {
        $groups = [];
        $assigned = [];
        foreach (StudioPermissions::OPERATIONS as $operation) {
            $ability = StudioPermissions::ability($operation);
            if (! isset($options[$ability]) || isset($assigned[$ability])) {
                continue;
            }
            $group = match (explode('.', $operation, 2)[0]) {
                'view', 'write', 'files', 'uploads', 'notes' => 'files',
                'folders' => 'folders',
                'workspaces', 'members' => 'people',
                'comments' => 'comments',
                'tags' => 'tags',
                'shares' => 'shares',
                'ai' => 'ai',
                'settings', 'notifications' => 'settings',
                default => 'integrations',
            };
            $groups[$group][$ability] = $options[$ability];
            $assigned[$ability] = true;
        }
        $groups['integrations'] = ($groups['integrations'] ?? []) + array_diff_key($options, $assigned);
        $sections = [];
        foreach (['files', 'folders', 'people', 'comments', 'tags', 'shares', 'ai', 'integrations', 'settings'] as $group) {
            $groupOptions = $groups[$group] ?? [];
            if ($groupOptions === []) {
                continue;
            }
            $keys = array_keys($groupOptions);
            $sections[] = Section::make(__('media-studio::permissions.groups.'.$group))
                ->compact()
                ->schema([
                    CheckboxList::make('media_studio_'.$group.'_permissions')
                        ->hiddenLabel()
                        ->options(array_map(static fn (string $label): string => str_starts_with($label, 'Media Studio · ') ? substr($label, strlen('Media Studio · ')) : $label, $groupOptions))
                        ->in($keys)
                        ->columns(1)
                        ->bulkToggleable()
                        ->afterStateHydrated(static function (CheckboxList $component, ?Model $record) use ($keys): void {
                            $selected = $record?->getRelationValue('permissions')?->pluck('name')->all() ?? [];
                            $component->state(array_values(array_intersect($selected, $keys)));
                        }),
                ]);
        }

        return Tab::make('Media Studio')
            ->key('media-studio-permissions')
            ->badge(count($options))
            ->columns(['default' => 1, 'lg' => 2])
            ->schema($sections);
    }

    /** @param array<Component> $schema */
    private static function configureSelectAll(array $schema): void
    {
        $fields = [];
        foreach (self::fields($schema) as $field) {
            $fields[$field->getName()] = array_keys($field->getOptions());
        }
        foreach ($schema as $component) {
            if (! $component instanceof Toggle || $component->getName() !== 'select_all') {
                continue;
            }
            $component->afterStateHydrated(static function (Toggle $component, ?Model $record) use ($fields): void {
                $selected = $record?->getRelationValue('permissions')?->pluck('name')->all() ?? [];
                $all = array_merge(...array_values($fields));
                $component->state($all !== [] && array_diff($all, $selected) === []);
            })->clearAfterStateUpdatedHooks()
                ->afterStateUpdated(static function (bool $state, Set $set) use ($fields): void {
                    foreach ($fields as $name => $keys) {
                        $set($name, $state ? $keys : []);
                    }
                });
        }
    }

    /** @return list<CheckboxList> */
    private static function fields(mixed $schema): array
    {
        if (! is_array($schema)) {
            return [];
        }
        $fields = [];
        foreach ($schema as $component) {
            if ($component instanceof CheckboxList) {
                $fields[] = $component;
            } elseif ($component instanceof Component) {
                array_push($fields, ...self::fields($component->getDefaultChildComponents()));
            }
        }

        return $fields;
    }
}
