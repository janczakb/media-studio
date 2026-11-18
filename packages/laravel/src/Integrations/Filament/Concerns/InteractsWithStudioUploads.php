<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Concerns;

use Closure;
use Filament\Actions\Action;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Library\MediaReferences;
use UseFlex\MediaStudio\Library\ThumbnailProjection;
use UseFlex\MediaStudio\Library\UploadAsset;
use UseFlex\MediaStudio\Integrations\Filament\StudioFolderScope;
use UseFlex\MediaStudio\Integrations\Filament\StudioReferenceSelection;
use UseFlex\MediaStudio\Integrations\Filament\StudioPicker;
use UseFlex\MediaStudio\Integrations\Filament\StudioReference;

trait InteractsWithStudioUploads
{
    private string|Closure $studioWorkspace;

    private string|Closure|null $studioFolder = null;

    private bool|Closure $studioGenerateRenditions = true;

    /** @var list<string>|null */
    private ?array $studioMimeTypes = null;

    private string|Closure|null $studioBrowseFolder = null;

    private string|Closure|null $studioBrowseScope = null;

    private bool|Closure $studioUploadEnabled = true;

    private bool|Closure $studioLibraryEnabled = true;

    private ?Closure $studioPickerConfiguration = null;

    private bool|Closure $studioPersistImmediately = false;

    private bool $studioPersisting = false;

    private string $studioMetadataKey = '';

    private array $studioMetadata = [];
    private string|Closure|null $studioThumbnailPreset = null;

    public function studioThumbnailPreset(string|Closure|null $preset): static
    {
        if (is_string($preset)) {
            ThumbnailProjection::validatePreset($preset);
        }
        $this->studioThumbnailPreset = $preset;

        return $this;
    }

    public function getStudioThumbnailPreset(): ?string
    {
        $preset = $this->evaluate($this->studioThumbnailPreset);
        ThumbnailProjection::validatePreset($preset);

        return $preset;
    }

    public function persistStudioUploadsImmediately(bool|Closure $enabled = true): static
    {
        $this->studioPersistImmediately = $enabled;

        return $this;
    }

    public function studioGenerateRenditions(bool|Closure $enabled = true): static
    {
        $this->studioGenerateRenditions = $enabled;

        return $this;
    }

    public function shouldGenerateStudioRenditions(): bool
    {
        return (bool) $this->evaluate($this->studioGenerateRenditions);
    }

    public function studioUpload(bool|Closure $enabled = true): static
    {
        $this->studioUploadEnabled = $enabled;

        return $this;
    }

    public function studioLibrarySelection(bool|Closure $enabled = true): static
    {
        $this->studioLibraryEnabled = $enabled;

        return $this;
    }

    public function studioUploadFolder(string|Closure|null $folder): static
    {
        $this->studioFolder = $folder;

        return $this;
    }

    public function studioBrowseFolder(string|Closure|null $folder): static
    {
        $this->studioBrowseFolder = $folder;

        return $this;
    }

    public function studioScopedFolder(string|Closure|null $folder): static
    {
        $this->studioBrowseScope = $folder;

        return $this;
    }

    public function configureStudioPickerUsing(?Closure $callback): static
    {
        $this->studioPickerConfiguration = $callback;

        return $this;
    }

    public function isStudioUploadEnabled(): bool
    {
        return ! $this->isDisabled() && (bool) $this->evaluate($this->studioUploadEnabled);
    }

    public function isStudioLibrarySelectionEnabled(): bool
    {
        return ! $this->isDisabled()
            && (bool) $this->evaluate($this->studioLibraryEnabled);
    }

    private function studioWorkspaceId(): string
    {
        $value = $this->evaluate($this->studioWorkspace);
        abort_unless(is_string($value) && Str::isUlid($value), 422, 'Configure a valid Studio workspace.');

        return $value;
    }

    private function studioFolderId(string|Closure|null $folder): ?string
    {
        $value = $this->evaluate($folder);
        abort_unless($value === null || (is_string($value) && Str::isUlid($value)), 422, 'Configure a valid Studio folder.');

        return $value;
    }

    public function studio(
        string|Closure $workspace,
        string $moduleUrl,
        ?string $stylesheetUrl = null,
        string|Closure|null $uploadFolder = null,
        ?array $acceptedMimeTypes = null,
        bool $allowLibrarySelection = true,
        bool $generateRenditions = true,
    ): static {
        if ((is_string($workspace) && ! Str::isUlid($workspace)) || (is_string($uploadFolder) && ! Str::isUlid($uploadFolder))) {
            throw new InvalidArgumentException('Studio requires a workspace ULID and, optionally, a folder ULID.');
        }

        $this->studioWorkspace = $workspace;
        $this->studioFolder = $uploadFolder;
        $this->studioGenerateRenditions = $generateRenditions;
        $this->studioLibraryEnabled = $allowLibrarySelection;
        $this->studioMimeTypes = StudioPicker::normalizeMimeTypes($acceptedMimeTypes);
        $this->fetchFileInformation(false)
            ->maxFiles(20)
            ->maxSize((int) config('media-studio.upload.max_kilobytes', 25600))
            ->saveUploadedFileUsing(fn (TemporaryUploadedFile $file): string => $this->publish($file))
            ->getUploadedFileUsing(fn (string $file): ?array => $this->fileInformation($file));

        if ($this->studioMimeTypes !== null) {
            $this->acceptedFileTypes($this->studioMimeTypes);
        }
        $action = Action::make('selectStudioFile')
            ->label(__('media-studio::editor.choose_files'))
            ->visible(fn (): bool => (bool) $this->evaluate($this->studioLibraryEnabled))
            ->disabled(fn (): bool => ! $this->isStudioLibrarySelectionEnabled())
            ->icon(Heroicon::OutlinedPhoto)
            ->modalWidth(Width::SevenExtraLarge)
            ->extraModalWindowAttributes(['class' => 'fms-filament-picker-modal'])
            ->modalSubmitActionLabel(__('media-studio::editor.use_file'))
            ->schema(function () use ($moduleUrl, $stylesheetUrl): array {
                $picker = StudioPicker::make('reference')->hiddenLabel()->required()
                    ->studio($this->studioWorkspaceId(), $moduleUrl, $stylesheetUrl)
                    ->folders($this->studioFolderId($this->studioBrowseFolder), $this->studioFolderId($this->studioBrowseScope))
                    ->acceptedMimeTypes($this->pickerMimeTypes())->allowUpload(false)
                    ->thumbnailPreset($this->getStudioThumbnailPreset())
                    ->selectionMessage(__('media-studio::editor.file_selected'));
                if ($this->isMultiple()) {
                    $picker->selectionLimit(min(120, $this->getMaxFiles() ?? 20))
                        ->required($this->isRequired() || ($this->getMinFiles() ?? 0) > 0)
                        ->rules(['array', 'min:'.max($this->isRequired() ? 1 : 0, $this->getMinFiles() ?? 0), 'max:'.min(120, $this->getMaxFiles() ?? 20)]);
                    $picker->selectedReferences(array_values(array_filter(array_map(
                        static fn (mixed $file): ?array => is_string($file) ? StudioReference::parse($file) : null,
                        Arr::wrap($this->getRawState()),
                    ))));
                }
                if ($this->studioPickerConfiguration !== null) {
                    $this->evaluate($this->studioPickerConfiguration, ['picker' => $picker]);
                }

                return [$picker];
            })
            ->action(function (array $data): void {
                abort_unless($this->isStudioLibrarySelectionEnabled(), 403);
                $selected = $this->isMultiple() ? ($data['reference'] ?? []) : [$data['reference'] ?? []];
                if (! is_array($selected) || ! array_is_list($selected) || count($selected) < ($this->isMultiple() ? max($this->isRequired() ? 1 : 0, $this->getMinFiles() ?? 0) : 1) || count($selected) > 120) {
                    throw ValidationException::withMessages(['reference' => __('media-studio::editor.file_unavailable')]);
                }
                $workspace = $this->studioWorkspaceId();
                $state = [];
                Validator::make(['references' => $selected], [
                    'references.*' => ['required', 'array:assetId,versionId,workspaceId,rendition'],
                    'references.*.assetId' => ['required', 'ulid'],
                    'references.*.versionId' => ['required', 'ulid'],
                    'references.*.workspaceId' => ['nullable', 'in:'.$workspace],
                    'references.*.rendition' => ['nullable', 'string', 'regex:/\\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\\z/'],
                ])->validate();
                $resolver = app(MediaReferences::class);
                $files = $resolver->resolve(StudioPicker::principal(), $workspace, $selected, presentation: true);
                $originals = array_values(array_map(static fn (array $reference): array => array_diff_key($reference, ['rendition' => true]),
                    array_filter($selected, static fn (array $reference): bool => isset($reference['rendition']))));
                $originalFiles = $originals === [] ? [] : $resolver->resolve(StudioPicker::principal(), $workspace, $originals);
                StudioFolderScope::assert($workspace, $files, $this->studioFolderId($this->studioBrowseScope));
                foreach ([...$files, ...$originalFiles] as $file) {
                    abort_unless($this->allowsMime($file['mimeType']), 422, __('media-studio::editor.unsupported_type'));
                }
                foreach ($files as $resolved) {
                    $value = StudioReference::key($resolved);
                    if (! in_array($value, $state, true)) {
                        $limit = min(120, $this->getMaxFiles() ?? 20);
                        if (count($state) >= $limit) {
                            throw ValidationException::withMessages(['reference' => __('media-studio::editor.file_limit', ['limit' => $limit])]);
                        }
                        $state[(string) Str::uuid()] = $value;
                    }
                }
                $this->rawState($state);
                $this->callAfterStateUpdated();
            });
        $this->configureStudioLibraryAction($action);
        $this->deleteUploadedFileUsing(static function (): void {});
        $this->getDownloadableFileUrlUsing(fn (string $file): ?string => $this->fileInformation($file)['downloadableUrl'] ?? null);
        $this->getOpenableFileUrlUsing(fn (string $file): ?string => $this->fileInformation($file)['openableUrl'] ?? null);
        $this->afterStateUpdated(function (): void {
            if ($this->studioPersisting || ! $this->evaluate($this->studioPersistImmediately) || $this->hasPendingStudioImageReplacement()) {
                return;
            }
            $this->pruneUnavailableStudioFiles();
            foreach (Arr::wrap($this->getRawState()) as $file) {
                if ($file instanceof TemporaryUploadedFile) {
                    $this->studioPersisting = true;
                    try {
                        // The form's minimum selection applies on submit, while all
                        // temporary-file and upper-bound rules apply before publishing.
                        $rules = array_values(array_filter($this->getValidationRules(),
                            static fn (mixed $rule): bool => ! is_string($rule) || ! str_starts_with($rule, 'min:')));
                        try {
                            Validator::make(['files' => $this->getRawState()], ['files' => $rules])->validate();
                        } catch (ValidationException $error) {
                            throw ValidationException::withMessages([$this->getStatePath() => $error->validator->errors()->first()]);
                        }
                        $this->saveUploadedFiles();
                    } finally {
                        $this->studioPersisting = false;
                    }

                    return;
                }
            }
        });

        return $this;
    }

    protected function configureStudioLibraryAction(Action $action): void
    {
        $this->hintAction($action);
    }

    public function hydrateFiles(): void
    {
        parent::hydrateFiles();
        $this->pruneUnavailableStudioFiles();
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function getUploadedFiles(): ?array
    {
        $this->pruneUnavailableStudioFiles();

        return parent::getUploadedFiles();
    }

    public function mutateStateForValidation(mixed $state): mixed
    {
        if (is_array($state)) {
            $state = StudioReferenceSelection::available($this->studioWorkspaceId(), $state);
            $this->rawState($state);
        }

        return parent::mutateStateForValidation($state);
    }

    public function saveUploadedFiles(): void
    {
        if ($this->hasPendingStudioImageReplacement()) {
            throw ValidationException::withMessages([$this->getStatePath() => __('media-studio::editor.upload_in_progress')]);
        }

        $this->pruneUnavailableStudioFiles();
        parent::saveUploadedFiles();
    }

    protected function hasPendingStudioImageReplacement(): bool
    {
        return false;
    }

    private function pruneUnavailableStudioFiles(): void
    {
        $values = $this->getRawState();
        if (is_array($values)) {
            $this->rawState(StudioReferenceSelection::available($this->studioWorkspaceId(), $values));
        }
    }

    public function getValidationRules(): array
    {
        StudioPicker::validateSelectionLimits($this->getMinFiles() ?? 0,
            $this->isMultiple() ? min(120, $this->getMaxFiles() ?? 20) : 1);

        return [...parent::getValidationRules(), function (string $attribute, mixed $value, Closure $fail): void {
            if (! is_array($value)) {
                return;
            }

            $limit = $this->isMultiple() ? min(120, $this->getMaxFiles() ?? 20) : 1;
            if (count($value) > $limit) {
                $fail(__('media-studio::editor.file_limit', ['limit' => $limit]));

                return;
            }

            $references = [];
            foreach ($value as $file) {
                if ($file instanceof TemporaryUploadedFile) {
                    continue;
                }
                $reference = is_string($file) ? self::parseReference($file) : null;
                if ($reference === null) {
                    $fail(__('media-studio::editor.file_unavailable'));

                    return;
                }
                $references[] = $reference;
            }
            if ($references === []) {
                return;
            }

            try {
                $files = app(MediaReferences::class)->resolve(StudioPicker::principal(), $this->studioWorkspaceId(), $references);
                StudioFolderScope::assert($this->studioWorkspaceId(), $files, $this->studioFolderId($this->studioBrowseScope));
                foreach ($files as $file) {
                    if (! $this->allowsMime($file['mimeType'])) {
                        $fail(__('media-studio::editor.unsupported_type'));

                        return;
                    }
                }
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404, 422], true)) {
                    throw $error;
                }
                $fail(__('media-studio::editor.file_unavailable'));
            }
        }];
    }

    /** @return array{assetId: string, versionId: string}|null */
    public static function parseReference(string $value): ?array
    {
        return StudioReference::parse($value);
    }

    private function publish(TemporaryUploadedFile $file): string
    {
        abort_unless($this->isStudioUploadEnabled(), 403);
        $principal = StudioPicker::principal();
        $access = app(WorkspaceAccess::class);
        $workspaceId = $this->studioWorkspaceId();
        $scope = $this->studioFolderId($this->studioBrowseScope);
        $folderId = $this->studioFolderId($this->studioFolder) ?? $scope;
        $workspace = $access->resolve($principal, $workspaceId, write: true);
        $this->authorizeUpload($principal, $workspace);
        $parentId = app(Entries::class)->parentId($workspace->id, $folderId, write: true);
        StudioFolderScope::assertParents($workspace->id, [$parentId], $scope);
        $maxBytes = max(1, min((int) config('media-studio.upload.max_kilobytes', 25600), $this->getMaxSize() ?? PHP_INT_MAX)) * 1024;
        $local = tmpfile();
        abort_if($local === false, 503, 'Could not prepare the uploaded file.');

        try {
            $source = $file->readStream();
            abort_unless(is_resource($source), 422, __('media-studio::editor.file_unavailable'));
            try {
                $bytes = stream_copy_to_stream($source, $local, $maxBytes + 1);
            } finally {
                fclose($source);
            }
            abort_unless(is_int($bytes) && $bytes > 0 && $bytes <= $maxBytes && $bytes === $file->getSize(), 422, __('media-studio::editor.file_unavailable'));
            $upload = new UploadedFile(stream_get_meta_data($local)['uri'], $file->getClientOriginalName(), test: true);
            if (! $this->allowsMime($upload->getMimeType() ?? 'application/octet-stream')) {
                throw ValidationException::withMessages([$this->getStatePath() => __('media-studio::editor.unsupported_type')]);
            }

            try {
                $entry = app(UploadAsset::class)->execute(
                    $workspace, $principal, $parentId, $upload,
                    hash('sha256', json_encode([$workspaceId, $folderId, $file->getFilename()], JSON_THROW_ON_ERROR)),
                    workspaceResolver: function (bool $lock = false) use ($access, $principal, $workspaceId, $parentId, $scope): object {
                        $current = $access->resolve($principal, $workspaceId, write: true, lock: $lock);
                        $this->authorizeUpload($principal, $current);
                        StudioFolderScope::assertParents($current->id, [$parentId], $scope);

                        return $current;
                    },
                    generateRenditions: $this->shouldGenerateStudioRenditions(),
                );
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [409, 422], true)) {
                    throw $error;
                }
                throw ValidationException::withMessages([$this->getStatePath() => $error->getMessage()]);
            }

            return StudioReference::key(['assetId' => $entry->public_id, 'versionId' => $entry->current_version_id]);
        } finally {
            fclose($local);
        }
    }

    private function authorizeUpload(Principal $principal, object $workspace): void
    {
        $permissions = app(StudioPermissions::class);
        $permissions->authorize($permissions->actor($principal), 'uploads.create', $workspace);
    }

    private function fileInformation(string $value): ?array
    {
        $values = array_values(array_filter(Arr::wrap($this->getRawState()), is_string(...)));
        if (count($values) > 120 || ! in_array($value, $values, true)) {
            return null;
        }
        $workspace = $this->studioWorkspaceId();
        $scope = $this->studioFolderId($this->studioBrowseScope);
        $preset = $this->getStudioThumbnailPreset();
        $key = hash('sha256', json_encode([$workspace, $scope, $values, $this->getAcceptedFileTypes(), $preset], JSON_THROW_ON_ERROR));
        if ($this->studioMetadataKey !== $key) {
            $references = array_values(array_filter(array_map(StudioReference::parse(...), $values)));
            $this->studioMetadata = [];
            try {
                $files = $references === [] ? [] : app(MediaReferences::class)->resolve(
                    StudioPicker::principal(), $workspace, $references, availableOnly: true, presentation: true, thumbnailPreset: $preset,
                );
                StudioFolderScope::assert($workspace, $files, $scope);
                foreach ($files as $file) {
                    if (! $this->allowsMime($file['mimeType'])) {
                        continue;
                    }
                    $this->studioMetadata[StudioReference::key($file)] = [
                        'name' => $file['name'],
                        'size' => $file['sizeBytes'],
                        'type' => $file['mimeType'],
                        'url' => $file['thumbnailUrl'] ?? null,
                        'openableUrl' => $file['url'],
                        'downloadableUrl' => $file['url'].'?download=1',
                    ];
                }
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404, 422], true)) {
                    throw $error;
                }
            }
            $this->studioMetadataKey = $key;
        }

        return $this->studioMetadata[$value] ?? null;
    }

    private function allowsMime(string $mime): bool
    {
        if ($this->studioMimeTypes !== null && ! StudioPicker::matchesMime($mime, $this->studioMimeTypes)) {
            return false;
        }
        $types = $this->getAcceptedFileTypes();
        if ($types === null) {
            return true;
        }
        foreach ($types as $type) {
            if ($type === $mime || (str_ends_with($type, '/*') && str_starts_with($mime, substr($type, 0, -1)))) {
                return true;
            }
        }

        return false;
    }
    private function pickerMimeTypes(): ?array
    {
        $native = StudioPicker::normalizeMimeTypes($this->getAcceptedFileTypes());
        if ($this->studioMimeTypes === null) {
            return $native;
        }
        if ($native === null) {
            return $this->studioMimeTypes;
        }
        $allowed = [];
        foreach ($this->studioMimeTypes as $studio) {
            foreach ($native as $type) {
                if (StudioPicker::matchesMime($type, [$studio])) {
                    $allowed[] = $type;
                } elseif (StudioPicker::matchesMime($studio, [$type])) {
                    $allowed[] = $studio;
                }
            }
        }
        if ($allowed === []) {
            throw new InvalidArgumentException('Studio and field MIME restrictions have no compatible types.');
        }

        return array_values(array_unique($allowed));
    }

}
