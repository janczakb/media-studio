<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Closure;
use Filament\Actions\BulkAction;
use Filament\Support\Icons\Heroicon;
use Filament\Tables\Contracts\HasTable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\LazyCollection;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Library\BulkEntries;

abstract class EntryBulkAction extends BulkAction
{
    use InteractsWithStudio;

    protected const OPERATION = '';

    private ?Closure $afterStudioEntries = null;

    public static function getDefaultName(): string
    {
        return 'studioBulk'.ucfirst(static::OPERATION);
    }

    public function afterEntries(?Closure $callback): static
    {
        $this->afterStudioEntries = $callback;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $operation = static::OPERATION;
        $icon = match ($operation) {
            'move' => Heroicon::OutlinedFolderArrowDown,
            'trash' => Heroicon::OutlinedTrash,
            'restore' => Heroicon::OutlinedArrowUturnLeft,
        };
        $this->label(__('media-studio::actions.'.$operation))
            ->icon($icon)
            ->deselectRecordsAfterCompletion()
            ->successNotificationTitle(__('media-studio::actions.completed'));

        $fields = [ActionSnapshot::field()];
        if ($operation === 'move') {
            $fields[] = FolderSelect::make(fn (): array => [$this->studioPrincipal(), $this->workspaceId()]);
        } else {
            $this->requiresConfirmation();
        }
        if ($operation === 'trash') {
            $this->color('danger');
        }
        $this->schema($fields)
            ->fillForm(function () use ($operation): array {
                $principal = $this->studioPrincipal();
                $workspace = $this->workspaceId();
                app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
                $form = [ActionSnapshot::FIELD => ActionSnapshot::issue($principal, $workspace, $this->snapshotAction(), $this->identities())];
                if ($operation === 'move') {
                    $form['parent'] = FolderSelect::ROOT;
                }

                return $form;
            })
            ->action(function (array $data) use ($operation): void {
                $principal = $this->studioPrincipal();
                $workspace = $this->workspaceId();
                $entries = ActionSnapshot::verify($data[ActionSnapshot::FIELD] ?? null, $principal, $workspace, $this->snapshotAction(), $this->identities());
                $parent = $operation === 'move' ? FolderSelect::parent($data['parent'] ?? null) : null;
                $updated = app(BulkEntries::class)->execute($principal, $workspace, $operation, $entries, $parent);
                $this->evaluate($this->afterStudioEntries, ['entries' => $updated]);
                $this->success();
            });
    }

    /** @return list<array{id:string,revision:int}> */
    private function identities(): array
    {
        $livewire = $this->getLivewire();
        abort_unless($livewire instanceof HasTable && ! $livewire->isTrackingDeselectedTableRecords, 422, __('media-studio::actions.explicit_selection'));
        $keys = $livewire->selectedTableRecords;
        abort_unless(is_array($keys) && count($keys) >= 1 && count($keys) <= BulkEntries::MAX_ITEMS, 422, __('media-studio::actions.explicit_selection'));
        foreach ($keys as $key) {
            abort_unless((is_string($key) || is_int($key)) && (string) $key !== '', 422, __('media-studio::actions.explicit_selection'));
        }
        abort_unless(count(array_unique($keys)) === count($keys), 422, __('media-studio::actions.explicit_selection'));

        $records = $livewire->getSelectedTableRecords();
        if ($records instanceof LazyCollection) {
            $records = $records->take(BulkEntries::MAX_ITEMS + 1)->collect();
        }
        abort_unless($records->count() === count($keys), 409, 'The selection changed. Reopen the action to confirm it.');
        $identities = [];
        foreach ($records as $record) {
            if ($this->shouldAuthorizeIndividualRecords()) {
                abort_unless($record instanceof Model, 403);
                $this->getIndividualRecordAuthorizationResponse($record)->authorize();
            }
            $identity = $this->entryIdentity($record);
            abort_if(isset($identities[$identity['id']]), 422, __('media-studio::actions.explicit_selection'));
            $identities[$identity['id']] = $identity;
        }

        return array_values($identities);
    }
}
