<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\RichEditor;
use Filament\Forms\Components\RichEditor\EditorCommand;
use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\HasFileAttachmentProvider;
use Filament\Forms\Components\RichEditor\Plugins\Contracts\RichContentPlugin;
use Filament\Forms\Components\RichEditor\RichEditorTool;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;

final readonly class RichEditorStudioPlugin implements HasFileAttachmentProvider, RichContentPlugin
{
    public const TOOL = 'insertStudioMedia';

    private ?array $acceptedMimeTypes;

    public function __construct(
        private string|array $workspace,
        private string $moduleUrl,
        private ?string $stylesheetUrl = null,
        private string $imageSizes = '(max-width: 768px) 100vw, 1000px',
        ?array $acceptedMimeTypes = StudioPicker::INLINE_IMAGE_MIMES,
        private bool $allowUpload = true,
        private bool $persistAttachmentIds = false,
    ) {
        $this->acceptedMimeTypes = StudioPicker::normalizeMimeTypes($acceptedMimeTypes);
    }

    public function getFileAttachmentProvider(): ?FileAttachmentProvider
    {
        return $this->persistAttachmentIds ? new StudioRichAttachmentProvider($this->workspace) : null;
    }

    public function getTipTapPhpExtensions(): array
    {
        return [new ResponsiveImages];
    }

    public function getTipTapJsExtensions(): array
    {
        return [preg_replace('#[^/]+(?:\?.*)?$#', 'rich-editor-extension.js', $this->moduleUrl)];
    }

    public function getEditorTools(): array
    {
        return [
            RichEditorTool::make(self::TOOL)
                ->label(__('media-studio::editor.library'))
                ->icon(Heroicon::OutlinedPhoto)
                ->action(),
        ];
    }

    public function getEditorActions(): array
    {
        return [
            Action::make(self::TOOL)
                ->label(__('media-studio::editor.library'))
                ->modalWidth(Width::SevenExtraLarge)
                ->modalSubmitActionLabel(__('media-studio::editor.insert'))
                ->schema([
                    StudioPicker::make('reference')
                        ->hiddenLabel()
                        ->studio($this->workspace, $this->moduleUrl, $this->stylesheetUrl)
                        ->acceptedMimeTypes($this->acceptedMimeTypes)
                        ->allowUpload($this->allowUpload)
                        ->suggestEditorAlt($this->workspace)
                        ->required(),
                    Grid::make(2)->schema([StudioPicker::renditionField($this->workspace, $this->acceptedMimeTypes),
                        TextInput::make('alt')
                            ->label(__('media-studio::editor.alt'))
                            ->maxLength(1000)]),
                ])
                ->action(function (array $arguments, array $data, RichEditor $component): void {
                    abort_if($component->isDisabled(), 403);
                    $alt = Validator::make($data, [
                        'alt' => ['nullable', 'string', 'max:1000'],
                    ])->validate()['alt'] ?? '';
                    $selection = Validator::make($arguments, [
                        'editorSelection' => ['required', 'array:type,anchor,head'],
                        'editorSelection.type' => ['required', Rule::in(['text', 'node', 'all'])],
                        'editorSelection.anchor' => ['required_unless:editorSelection.type,all', 'integer', 'min:0'],
                        'editorSelection.head' => ['sometimes', 'integer', 'min:0'],
                    ])->validate()['editorSelection'];

                    $file = StudioPicker::resolveSelection($this->workspace, $data, $this->acceptedMimeTypes);
                    $node = StudioPicker::isInlineImage($file['mimeType'])
                        ? ['type' => 'image', 'attrs' => ['src' => $file['url'], 'alt' => $alt, ...$this->imageAttributes($data, $file)]]
                        : ['type' => 'text', 'text' => $file['name'], 'marks' => [['type' => 'link', 'attrs' => ['href' => $file['url']]]]];

                    $component->runCommands(
                        [EditorCommand::make('insertContent', arguments: [$node])],
                        editorSelection: $selection,
                    );
                }),
        ];
    }

    private function imageAttributes(array $data, array $file): array
    {
        if (! $this->persistAttachmentIds) {
            return StudioPicker::responsiveAttributes($this->workspace, $data, $file, $this->imageSizes);
        }
        $workspace = StudioPicker::selectedWorkspace($this->workspace, $data);
        $parts = [$workspace, $file['assetId'], $file['versionId']];
        if (isset($file['rendition'])) {
            $parts[] = $file['rendition'];
        }

        return ['id' => implode(':', $parts)];
    }
}
