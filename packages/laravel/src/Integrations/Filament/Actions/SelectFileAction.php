<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Closure;
use Filament\Actions\Action;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;
use UseFlex\MediaStudio\Library\MediaReferences;

final class SelectFileAction extends Action
{
    use InteractsWithStudio;

    private ?Closure $selection = null;

    public static function getDefaultName(): string
    {
        return 'studioSelectFile';
    }

    public function assets(string $moduleUrl, ?string $stylesheetUrl = null): static
    {
        return $this->schema(fn (): array => [
            StudioPicker::make('reference')->hiddenLabel()->studio($this->workspaceId(), $moduleUrl, $stylesheetUrl)->required(),
        ]);
    }

    /** @param Closure(array{assetId:string,versionId:string,name:string,mimeType:string,url:string,rendition?:string}):void $callback */
    public function onSelect(Closure $callback): static
    {
        $this->selection = $callback;

        return $this;
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('media-studio::actions.select_file'))
            ->icon(Heroicon::OutlinedPhoto)
            ->modalWidth(Width::SevenExtraLarge)
            ->modalSubmitActionLabel(__('media-studio::actions.select_file'))
            ->mountUsing(function (?Schema $schema): void {
                app(WorkspaceAccess::class)->resolve($this->studioPrincipal(), $this->workspaceId());
                $schema?->fill();
            })
            ->action(function (array $data): void {
                $principal = $this->studioPrincipal();
                $reference = Validator::make($data, [
                    'reference' => ['required', 'array:assetId,versionId,rendition'],
                    'reference.assetId' => ['required', 'ulid'],
                    'reference.versionId' => ['required', 'ulid'],
                    'reference.rendition' => ['nullable', 'string', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/'],
                ])->validate()['reference'];
                $file = app(MediaReferences::class)->resolve($principal, $this->workspaceId(), [$reference])[0];
                if ($this->selection === null) {
                    $this->getLivewire()->dispatch('media-studio-file-selected', reference: $file);
                } else {
                    $this->evaluate($this->selection, ['reference' => $file]);
                }
            });
    }
}
