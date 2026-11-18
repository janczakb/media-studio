<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\TextInput;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Library\CreateFolder;

final class CreateFolderAction extends Action
{
    use InteractsWithStudio;

    private ?Closure $afterStudioEntry = null;

    public static function getDefaultName(): string
    {
        return 'studioCreateFolder';
    }

    public function afterEntry(?Closure $callback): static
    {
        $this->afterStudioEntry = $callback;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('media-studio::actions.create_folder'))
            ->icon(Heroicon::OutlinedFolderPlus)
            ->schema([
                ActionSnapshot::field(),
                TextInput::make('name')->label(__('media-studio::actions.name'))->required()->maxLength(191),
                FolderSelect::make(fn (): array => [$this->studioPrincipal(), $this->workspaceId()]),
            ])
            ->fillForm(function (): array {
                $principal = $this->studioPrincipal();
                $workspace = $this->workspaceId();
                app(WorkspaceAccess::class)->resolve($principal, $workspace, write: true);

                return ['parent' => FolderSelect::ROOT,
                    ActionSnapshot::FIELD => ActionSnapshot::issue($principal, $workspace, $this->snapshotAction(), [])];
            })
            ->successNotificationTitle(__('media-studio::actions.completed'))
            ->action(function (array $data): void {
                $principal = $this->studioPrincipal();
                $workspace = $this->workspaceId();
                ActionSnapshot::verify($data[ActionSnapshot::FIELD] ?? null, $principal, $workspace, $this->snapshotAction(), []);
                $name = Validator::make($data, ['name' => ['required', 'string', 'max:191']])->validate()['name'];
                $folder = app(CreateFolder::class)->execute($principal, $workspace, $name, FolderSelect::parent($data['parent'] ?? null));
                $this->evaluate($this->afterStudioEntry, ['entry' => $folder]);
                $this->success();
            });
    }
}
