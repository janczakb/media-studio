<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\EntryOperations;

abstract class EntryAction extends Action
{
    use InteractsWithStudio;

    protected const OPERATION = '';

    public static function getDefaultName(): string
    {
        return 'studio'.ucfirst(static::OPERATION);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $operation = static::OPERATION;
        $icon = match ($operation) {
            'rename' => Heroicon::OutlinedPencilSquare,
            'move' => Heroicon::OutlinedFolderArrowDown,
            'trash' => Heroicon::OutlinedTrash,
            'restore' => Heroicon::OutlinedArrowUturnLeft,
        };
        $this->label(__('media-studio::actions.'.$operation))
            ->icon($icon)
            ->successNotificationTitle(__('media-studio::actions.completed'));

        $fields = [ActionSnapshot::field()];
        if ($operation === 'rename') {
            $fields[] = TextInput::make('name')->label(__('media-studio::actions.name'))->required()->maxLength(191);
        } elseif ($operation === 'move') {
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
                $identity = $this->entryIdentity($this->getRecord());
                $scope = app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);
                $entry = app(Entries::class)->resolve($scope->id, $identity['id']);
                app(SourcePolicy::class)->managed($entry);
                $form = [ActionSnapshot::FIELD => ActionSnapshot::issue($principal, $workspace, $this->snapshotAction(), [$identity])];

                if ($operation === 'rename') {
                    $form['name'] = $entry->name;
                } elseif ($operation === 'move') {
                    $form['parent'] = $entry->parent_id === null ? FolderSelect::ROOT
                        : (DB::table('fms_entries')->where('workspace_id', $scope->id)
                            ->where('id', $entry->parent_id)->value('public_id') ?? FolderSelect::ROOT);
                }

                return $form;
            })
            ->action(function (array $data) use ($operation): void {
                $principal = $this->studioPrincipal();
                $workspace = $this->workspaceId();
                $identity = $this->entryIdentity($this->getRecord());
                $confirmed = ActionSnapshot::verify($data[ActionSnapshot::FIELD] ?? null, $principal, $workspace, $this->snapshotAction(), [$identity])[0];
                $values = ['revision' => $confirmed['revision']];
                if ($operation === 'rename') {
                    $values += Validator::make($data, ['name' => ['required', 'string', 'max:191']])->validate();
                } elseif ($operation === 'move') {
                    $values['parent'] = FolderSelect::parent($data['parent'] ?? null);
                }
                $entry = app(EntryOperations::class)->execute($principal, $workspace, $confirmed['id'],
                    in_array($operation, ['rename', 'move'], true) ? 'update' : $operation, $values);
                $this->evaluate($this->afterStudioEntry, ['entry' => $entry]);
                $this->success();
            });
    }

    protected ?\Closure $afterStudioEntry = null;

    public function afterEntry(?\Closure $callback): static
    {
        $this->afterStudioEntry = $callback;

        return $this;
    }
}
