<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\FlexFields;

use Bjanczak\FilamentFlexFields\Filament\Actions\Action as FlexAction;
use Bjanczak\FilamentFlexFields\Filament\Actions\EditImageWithAiAction;
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload;
use Bjanczak\FilamentFlexFields\Support\FileUpload\Media\UploadAdmission;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaFailure;
use Bjanczak\FilamentFlexFields\Support\Media\ImageEditing\ImageEditModels;
use Closure;
use Filament\Actions\Action;
use Filament\Actions\Contracts\HasActions;
use Filament\Schemas\Components\Grid;
use Filament\Schemas\Components\View;
use Filament\Support\Components\Attributes\ExposedLivewireMethod;
use Filament\Support\Enums\Width;
use Livewire\Attributes\Renderless;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UseFlex\MediaStudio\Integrations\Filament\Concerns\InteractsWithStudioUploads;

/** Optional FLEX Fields presentation, with the same Studio storage and picker contract. */
final class StudioFileUpload extends FlexFileUpload
{
    use InteractsWithStudioUploads;

    private ?Closure $studioImageEditor = null;

    private ?Closure $publishEditedImage = null;

    private bool|Closure $studioInlineEditedThumbnail = false;

    public function studioInlineEditedThumbnail(bool|Closure $enabled = true): static
    {
        $this->studioInlineEditedThumbnail = $enabled;

        return $this;
    }

    public function getStudioInlineEditedThumbnailPreset(): ?string
    {
        if (! $this->shouldGenerateStudioRenditions() || ! $this->evaluate($this->studioInlineEditedThumbnail)) {
            return null;
        }

        return $this->getStudioThumbnailPreset() ?? config('media-studio.processing.thumbnails.preset', 'thumbnail-v1');
    }

    /**
     * Publish a new version after the native editor submits its output.
     * The publisher receives deletePreviousVersion, the field's deleteReplacedFiles policy.
     */
    public function studioImageEditor(Closure $publish): static
    {
        $this->publishEditedImage = $publish;

        return $this->imageEditor();
    }

    protected function resolveStoredImageSource(string $file): array
    {
        $source = $this->fileInformation($file);
        if ($source === null || ! str_starts_with($source['type'], 'image/')) {
            throw new MediaFailure('image_source_unavailable', 'The original image is unavailable.');
        }

        return ['name' => $source['name'], 'type' => $source['type'], 'url' => $source['openableUrl']];
    }

    protected function hasPendingStudioImageReplacement(): bool
    {
        $state = $this->getRawState();
        if ($this->publishEditedImage === null || ! is_array($state)) {
            return false;
        }

        foreach ($state as $key => $file) {
            if ($file instanceof TemporaryUploadedFile && str_starts_with((string) $key, 'fff-editor-')) {
                return true;
            }
        }

        return false;
    }

    protected function imageEditorReplacementValue(TemporaryUploadedFile $new, TemporaryUploadedFile|string $old): TemporaryUploadedFile|string
    {
        if ($this->publishEditedImage === null || ! is_string($old)) {
            return parent::imageEditorReplacementValue($new, $old);
        }

        app(UploadAdmission::class)->validate($this, $new);
        $reference = $this->evaluate($this->publishEditedImage, [
            'file' => $new,
            'reference' => $old,
            'deletePreviousVersion' => $this->shouldDeleteReplacedFiles(),
        ]);
        if (! is_string($reference)) {
            throw new MediaFailure('image_edit_source_changed', 'The edited image version could not be associated.');
        }

        return $reference;
    }

    /**
     * Connect the native FLEX tile action to application-owned Studio version handling.
     * The callback must configure the source, scope and explicit acceptance.
     */
    public function studioAiImageEditor(Closure $configure): static
    {
        if ($this->studioImageEditor === null) {
            $this->registerActions([function (): EditImageWithAiAction {
                $action = EditImageWithAiAction::make();
                $this->evaluate($this->studioImageEditor, ['action' => $action]);

                return $action->visible(fn (): bool => $this->hasAiImageEditor());
            }]);
        }

        $this->studioImageEditor = $configure;

        return $this;
    }

    public function hasAiImageEditor(): bool
    {
        return $this->studioImageEditor !== null
            && ! $this->isDisabled()
            && app(ImageEditModels::class)->configured() !== [];
    }

    public function registerFileViewerAction(): void
    {
        $this->registerActions([function (): FlexAction {
            $label = __('filament-flex-fields::default.file_upload.viewer.open');
            $action = FlexAction::make('viewFile')
                ->label($label)->icon('hugeicons-view')
                ->visible(fn (): bool => $this->hasFileViewer())
                ->modalWidth(Width::Screen)->modalHeading('')
                ->closeModalByClickingAway(false)->closeModalByEscaping(false)
                ->modalCloseButton(false)->modalSubmitAction(false)->modalCancelAction(false)->modalFooterActions([])
                ->extraModalOverlayAttributes(['class' => 'fff-upload-lightbox-overlay'])
                ->extraModalWindowAttributes([
                    'class' => 'fff-upload-lightbox-window fms-flex-preview-window',
                    'data-fff-loading-presentation' => 'lightbox',
                    'aria-label' => $label,
                ])
                ->schema(function (array $arguments, Action $action): array {
                    $key = $arguments['fileKey'] ?? null;
                    $items = $this->getFileViewerItems();
                    $this->assertViewerKey($key, $items);

                    return [Grid::make(1)->extraAttributes(['class' => 'fff-upload-lightbox-layout'])->schema([
                        View::make('filament-flex-fields::forms.components.file-upload.viewer')->viewData([
                            'viewerField' => $this,
                            'viewerItems' => $items,
                            'viewerKey' => $key,
                            'viewerCancelParentActions' => $action->getParentActionsToCancelOnClose(),
                        ]),
                    ])];
                });
            $this->evaluate($this->flexViewerAction, ['action' => $action]);

            return $action;
        }]);
    }

    public function getFileViewerItems(): array
    {
        if (! $this->hasFileViewer()) {
            throw new MediaFailure('denied', 'The attachment viewer is disabled.');
        }

        // Studio's upload callback resolves the pinned version and authorizes it in a batch.
        $items = [];
        foreach ($this->getUploadedFiles() ?? [] as $key => $file) {
            if ($file === null || ! in_array($file['type'], ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'], true)
                || ! is_string($file['openableUrl'] ?? null)) {
                continue;
            }

            $items[] = ['key' => (string) $key, 'name' => $file['name'], 'type' => $file['type'], 'url' => $file['openableUrl']];
        }

        return $items;
    }

    #[ExposedLivewireMethod]
    #[Renderless]
    public function navigateFileViewer(string $key): bool
    {
        $livewire = $this->getLivewire();
        $action = $livewire instanceof HasActions ? $livewire->getMountedAction() : null;
        if ($action?->getName() !== 'viewFile' || $action->getSchemaComponent() !== $this) {
            throw new MediaFailure('denied', 'The viewer does not belong to this field.');
        }
        $this->assertViewerKey($key, $this->getFileViewerItems());
        $livewire->mergeMountedActionArguments(['fileKey' => $key]);

        return true;
    }

    private function assertViewerKey(mixed $key, array $items): void
    {
        if (! is_string($key) || strlen($key) > 128 || ! in_array($key, array_column($items, 'key'), true)) {
            throw new MediaFailure('file_missing', 'The image no longer belongs to this field.');
        }
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->persistStudioUploadsImmediately();
        $this->fileUploadSourceLabel(fn (): string => __('media-studio::editor.upload_file'));
    }

    /** Studio retains original ownership when a form drops a reference. */
    public function deleteStoredPathWithVariants(string $path): int
    {
        return 0;
    }

    protected function configureStudioLibraryAction(Action $action): void
    {
        $this->sourceActions([
            $action->icon(null)->view('media-studio::filament.flex-fields-source-action'),
        ]);
        $this->fileUploadSourceEnabled(fn (): bool => $this->isStudioUploadEnabled());
    }
}
