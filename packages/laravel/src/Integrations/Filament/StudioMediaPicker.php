<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Closure;
use Filament\Actions\Action;
use Filament\Forms\Components\Field;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\MediaReferences;

final class StudioMediaPicker extends Field
{
    protected string $view = 'media-studio::filament.media-picker';

    private string|Closure $studioWorkspace;

    private string $studioModule;

    private ?string $studioStyle = null;

    private string|Closure|null $initialFolder = null;

    private string|Closure|null $folderScope = null;

    /** @var list<string>|null */
    private ?array $mimeTypes = null;
    private string|Closure|null $studioThumbnailPreset = null;

    public function studioThumbnailPreset(string|Closure|null $preset): static
    {
        if (is_string($preset)) {
            \UseFlex\MediaStudio\Library\ThumbnailProjection::validatePreset($preset);
        }
        $this->studioThumbnailPreset = $preset;

        return $this;
    }


    private bool $multiple = false;

    private bool $upload = false;

    private bool $reorderable = false;

    private bool|Closure $downloadable = false;

    /** @var list<string> */
    private array $fileActionNames = [];

    private bool $circular = false;

    private string|Closure $layout = 'list';

    private bool|Closure $layoutSwitcher = true;

    private ?Closure $studioPickerConfiguration = null;

    private int $minimum = 0;

    private int $maximum = 24;

    public function studio(string|Closure $workspace, string $moduleUrl, ?string $stylesheetUrl = null): static
    {
        $this->studioWorkspace = $workspace;
        $this->studioModule = $moduleUrl;
        $this->studioStyle = $stylesheetUrl;

        return $this;
    }

    public function acceptedFileTypes(?array $types): static
    {
        $this->mimeTypes = StudioPicker::normalizeMimeTypes($types);

        return $this;
    }

    public function defaultFolder(string|Closure|null $folder): static
    {
        $this->initialFolder = $folder;

        return $this;
    }

    public function scopedFolder(string|Closure|null $folder): static
    {
        $this->folderScope = $folder;

        return $this;
    }

    public function multiple(bool $enabled = true): static
    {
        $this->multiple = $enabled;

        return $this;
    }

    public function minFiles(int $minimum): static
    {
        $this->minimum = max(0, min(120, $minimum));

        return $this;
    }

    public function maxFiles(int $maximum): static
    {
        $this->maximum = max(1, min(120, $maximum));

        return $this;
    }

    public function allowUpload(bool $enabled = true): static
    {
        $this->upload = $enabled;

        return $this;
    }

    public function reorderable(bool $enabled = true): static
    {
        $this->reorderable = $enabled;

        return $this;
    }

    public function isStudioReorderable(): bool
    {
        return $this->multiple && $this->reorderable;
    }

    public function downloadable(bool|Closure $enabled = true): static
    {
        $this->downloadable = $enabled;

        return $this;
    }

    public function isStudioDownloadable(): bool
    {
        return (bool) $this->evaluate($this->downloadable);
    }

    /**
     * @param list<Action> $actions
     */
    public function fileActions(array $actions): static
    {
        foreach ($actions as $action) {
            if (! $action instanceof Action || $action->getName() === 'pickStudioMedia' || in_array($action->getName(), $this->fileActionNames, true)) {
                throw new \InvalidArgumentException('File actions must have unique names, distinct from pickStudioMedia.');
            }

            $configured = clone $action;
            $callback = $configured->getActionFunction();
            if ($callback !== null) {
                $configured->action(function (Action $action) use ($callback): mixed {
                    abort_if($this->isDisabled(), 403);
                    $file = $this->getStudioActionFile($action->getArguments());

                    return $action->evaluate($callback, ['file' => $file]);
                });
            }
            $this->fileActionNames[] = $configured->getName();
            $this->registerActions([$configured]);
        }

        return $this;
    }

    /** @return list<Action> */
    public function getStudioFileActions(int $index): array
    {
        $reference = $this->keys()[$index] ?? null;
        if ($reference === null || $this->isDisabled()) {
            return [];
        }

        $actions = [];
        foreach ($this->fileActionNames as $name) {
            $action = clone $this->getAction($name);
            $action->arguments(['mediaReference' => $reference]);
            if ($action->isVisible()) {
                $actions[] = $action;
            }
        }

        return $actions;
    }

    /** Resolve an action's pinned reference against the current field and workspace. */
    public function getStudioActionFile(array $arguments): array
    {
        $reference = $arguments['mediaReference'] ?? null;
        abort_unless(is_string($reference) && in_array($reference, $this->keys(), true), 404);
        $files = app(StudioMediaDisplay::class)->resolve($this->workspace(), [$reference]);
        abort_unless(isset($files[$reference]), 404);

        return $files[$reference];
    }

    public function circular(bool $enabled = true): static
    {
        $this->circular = $enabled;

        return $this;
    }

    public function isStudioCircular(): bool
    {
        return $this->circular;
    }

    public function configureStudioPickerUsing(?Closure $callback): static
    {
        $this->studioPickerConfiguration = $callback;

        return $this;
    }

    public function layout(string|Closure $layout): static
    {
        $this->layout = $layout;

        return $this;
    }

    public function layoutSwitcher(bool|Closure $enabled = true): static
    {
        $this->layoutSwitcher = $enabled;

        return $this;
    }

    public function hasStudioLayoutSwitcher(): bool
    {
        return (bool) $this->evaluate($this->layoutSwitcher);
    }

    public function getStudioLayout(): string
    {
        return $this->evaluate($this->layout) === 'grid' ? 'grid' : 'list';
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->default(fn (): ?array => $this->multiple ? [] : null);
        $this->afterStateHydrated(fn () => $this->pruneUnavailableStudioMedia());
        $this->registerActions([
            Action::make('pickStudioMedia')
                ->label(__('media-studio::editor.choose_files'))
                ->view('media-studio::filament.picker-source-action')
                ->extraAttributes(['class' => 'fms-filament-picker-trigger'])
                ->disabled(fn (): bool => $this->isDisabled())
                ->modalWidth(Width::SevenExtraLarge)
                ->extraModalWindowAttributes(['class' => 'fms-filament-picker-modal'])
                ->modalSubmitActionLabel(__('media-studio::editor.use_file'))
                ->schema(function (): array {
                    $picker = StudioPicker::make('references')->hiddenLabel()->required()
                        ->studio($this->workspace(), $this->studioModule, $this->studioStyle)
                        ->folders($this->evaluate($this->initialFolder), $this->evaluate($this->folderScope))
                        ->acceptedMimeTypes($this->mimeTypes)
                        ->thumbnailPreset($this->evaluate($this->studioThumbnailPreset))
                        ->allowUpload($this->upload)
                        ->selectionMessage(__('media-studio::editor.file_selected'));

                    if ($this->multiple) {
                        $picker->selectionLimit($this->maximum)
                            ->required($this->isRequired() || $this->minimum > 0)
                            ->rules(['array', 'min:'.max($this->isRequired() ? 1 : 0, $this->minimum), 'max:'.$this->maximum]);
                        $picker->selectedReferences(array_values(array_filter(array_map(StudioReference::parse(...), $this->keys()))));
                    }
                    if ($this->studioPickerConfiguration !== null) {
                        $this->evaluate($this->studioPickerConfiguration, ['picker' => $picker]);
                    }

                    return [$picker];
                })
                ->action(function (array $data): void {
                    abort_if($this->isDisabled(), 403);
                    $selected = $this->multiple ? $data['references'] : [$data['references']];
                    Validator::make(['references' => $selected], [
                        'references' => ['present', 'array', 'list', 'min:'.($this->multiple ? max($this->isRequired() ? 1 : 0, $this->minimum) : 1), 'max:'.($this->multiple ? $this->maximum : 1)],
                        'references.*' => ['required', 'array:workspaceId,assetId,versionId'],
                        'references.*.workspaceId' => ['nullable', 'in:'.$this->workspace()],
                        'references.*.assetId' => ['required', 'ulid'],
                        'references.*.versionId' => ['required', 'ulid'],
                    ])->validate();
                    $incoming = array_map(StudioReference::key(...), $selected);
                    $values = array_values(array_unique($incoming));
                    if (count($values) > $this->maximum) {
                        throw ValidationException::withMessages(['references' => __('media-studio::editor.file_limit', ['limit' => $this->maximum])]);
                    }
                    // Validate the complete edited selection again before committing it to the field.
                    $this->validateReferences($incoming);
                    $this->write($values);
                }),
        ]);
    }

    public function getValidationRules(): array
    {
        StudioPicker::validateSelectionLimits($this->minimum, $this->multiple ? $this->maximum : 1);

        return [...parent::getValidationRules(), ...($this->minimum > 0 ? ['required'] : []), function (string $attribute, mixed $value, Closure $fail): void {
            if (($this->multiple && ! is_array($value)) || (! $this->multiple && ! is_string($value))) {
                $fail(__('media-studio::editor.file_unavailable'));

                return;
            }
            $values = Arr::wrap($value);
            if (count($values) < $this->minimum || count($values) > ($this->multiple ? $this->maximum : 1)) {
                $fail(__('media-studio::editor.file_count', ['min' => $this->minimum, 'max' => $this->multiple ? $this->maximum : 1]));

                return;
            }

            try {
                $this->validateReferences($values);
            } catch (ValidationException $error) {
                $fail($error->validator->errors()->first());
            }
        }];
    }

    public function getSelectedStudioMedia(): array
    {
        $this->pruneUnavailableStudioMedia();
        $values = $this->keys();
        $files = app(StudioMediaDisplay::class)->resolve($this->workspace(), $values, $this->evaluate($this->studioThumbnailPreset));

        return array_map(static fn (string $value): ?array => $files[$value] ?? null, $values);
    }

    public function mutateStateForValidation(mixed $state): mixed
    {
        $this->pruneUnavailableStudioMedia();

        return parent::mutateStateForValidation($this->getState());
    }

    private function pruneUnavailableStudioMedia(): void
    {
        $state = $this->getState();
        $values = StudioReferenceSelection::available($this->workspace(), Arr::wrap($state));
        $cleaned = $this->multiple ? array_values($values) : (array_values($values)[0] ?? null);
        if ($state !== $cleaned) {
            $this->state($cleaned);
        }
    }

    #[ExposedLivewireMethod]
    public function removeStudioMedia(int $index): void
    {
        abort_if($this->isDisabled(), 403);
        $values = $this->keys();
        if (isset($values[$index])) {
            array_splice($values, $index, 1);
            $this->write($values);
        }
    }

    #[ExposedLivewireMethod]
    public function moveStudioMedia(int $index, int $offset): void
    {
        abort_unless(! $this->isDisabled() && $this->isStudioReorderable(), 403);
        $values = $this->keys();
        if ($offset < -119 || $offset > 119 || ! isset($values[$index])) {
            return;
        }
        $target = $index + $offset;
        if ($target !== $index && isset($values[$target])) {
            $moved = array_splice($values, $index, 1);
            array_splice($values, $target, 0, $moved);
            $this->write($values);
        }
    }

    private function workspace(): string
    {
        $workspace = $this->evaluate($this->studioWorkspace);
        abort_unless(is_string($workspace) && Str::isUlid($workspace), 422, 'Choose a valid Studio workspace.');

        return $workspace;
    }

    private function keys(): array
    {
        return array_values(array_filter(array_slice(Arr::wrap($this->getState()), 0, 120), is_string(...)));
    }

    private function write(array $values): void
    {
        $this->state($this->multiple ? array_values($values) : ($values[0] ?? null));
        $this->callAfterStateUpdated();
    }

    private function validateReferences(array $values): void
    {
        $references = [];
        foreach ($values as $value) {
            $reference = is_string($value) ? StudioReference::parse($value) : null;
            if ($reference === null) {
                throw ValidationException::withMessages(['references' => __('media-studio::editor.file_unavailable')]);
            }
            $references[] = $reference;
        }
        if ($references === []) {
            return;
        }

        try {
            $principal = StudioPicker::principal();
            $workspace = $this->workspace();
            foreach (app(MediaReferences::class)->resolve($principal, $workspace, $references) as $file) {
                if ($this->mimeTypes !== null && ! StudioPicker::matchesMime($file['mimeType'], $this->mimeTypes)) {
                    throw ValidationException::withMessages(['references' => __('media-studio::editor.unsupported_type')]);
                }
            }
            StudioFolderScope::assert($workspace, $references, $this->evaluate($this->folderScope));
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404, 422], true)) {
                throw $error;
            }
            throw ValidationException::withMessages(['references' => __('media-studio::editor.file_unavailable')]);
        }
    }

}
