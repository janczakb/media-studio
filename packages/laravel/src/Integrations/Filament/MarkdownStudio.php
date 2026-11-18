<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Actions\Action;
use Filament\Forms\Components\MarkdownEditor;
use Filament\Forms\Components\TextInput;
use Filament\Schemas\Components\Grid;
use Filament\Support\Enums\Width;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Js;

final class MarkdownStudio
{
    public static function attach(
        MarkdownEditor $editor,
        string|array $workspace,
        string $moduleUrl,
        ?string $stylesheetUrl = null,
        ?array $acceptedMimeTypes = StudioPicker::INLINE_IMAGE_MIMES,
        bool $allowUpload = true,
    ): MarkdownEditor {
        $acceptedMimeTypes = StudioPicker::normalizeMimeTypes($acceptedMimeTypes);

        return $editor
            ->extraAlpineAttributes([
                'x-on:media-studio-insert-markdown.window' => self::insertScript(),
            ], merge: true)
            ->hintActions([
                Action::make('insertStudioMedia')
                    ->label(__('media-studio::editor.library'))
                    ->icon(Heroicon::OutlinedPhoto)
                    ->modalWidth(Width::SevenExtraLarge)
                    ->modalSubmitActionLabel(__('media-studio::editor.insert'))
                    ->extraAttributes(fn (): array => [
                        'x-data' => '{}',
                        'x-on:click.capture' => self::captureScript($editor),
                    ])
                    ->schema([
                        StudioPicker::make('reference')
                            ->hiddenLabel()
                            ->studio($workspace, $moduleUrl, $stylesheetUrl)
                            ->acceptedMimeTypes($acceptedMimeTypes)
                            ->allowUpload($allowUpload)
                            ->suggestEditorAlt($workspace)
                            ->required(),
                        Grid::make(2)->schema([StudioPicker::renditionField($workspace, $acceptedMimeTypes),
                            TextInput::make('alt')->label(__('media-studio::editor.alt'))->maxLength(1000)]),
                    ])
                    ->action(function (array $data) use ($editor, $workspace, $acceptedMimeTypes): void {
                        abort_if($editor->isDisabled(), 403);
                        $file = StudioPicker::resolveSelection($workspace, $data, $acceptedMimeTypes);
                        $alt = Validator::make($data, ['alt' => ['nullable', 'string', 'max:1000']])->validate()['alt'] ?? '';
                        $image = StudioPicker::isInlineImage($file['mimeType']);
                        $label = self::escapeLabel($image ? $alt : $file['name']);
                        $url = strtr($file['url'], [' ' => '%20', '(' => '%28', ')' => '%29', '<' => '%3C', '>' => '%3E', '\\' => '%5C']);
                        $livewire = $editor->getLivewire();
                        $livewire->dispatch('media-studio-insert-markdown',
                            livewireId: $livewire->getId(),
                            editorId: $editor->getId(),
                            markdown: ($image ? '!' : '').'['.$label.']('.$url.')',
                        );
                    }),
            ]);
    }

    private static function escapeLabel(string $label): string
    {
        return strtr($label, [
            '\\' => '\\\\', '[' => '\\[', ']' => '\\]', '*' => '\\*', '_' => '\\_', '`' => '\\`',
            '<' => '\\<', '>' => '\\>', '!' => '\\!', '&' => '\\&', '~' => '\\~', "\n" => ' ', "\r" => '',
        ]);
    }

    private static function captureScript(MarkdownEditor $editor): string
    {
        $id = Js::from($editor->getId());
        $livewireId = Js::from($editor->getLivewire()->getId());

        return <<<JS
            const root = \$el.closest('[wire\\\\:id]');
            const target = root?.querySelector('#' + CSS.escape({$id}));
            const cm = target?._editor?.codemirror;
            if (!cm || target.closest('[wire\\\\:id]')?.getAttribute('wire:id') !== {$livewireId} || cm.getOption('readOnly')) {
                \$event.preventDefault();
                \$event.stopImmediatePropagation();
                return;
            }
            target._flexMediaStudioBookmark = {
                editor: cm, generation: cm.changeGeneration(true),
                from: cm.getCursor('from'), to: cm.getCursor('to'),
            };
            JS;
    }

    private static function insertScript(): string
    {
        $unavailable = Js::from(__('media-studio::editor.selection_changed'));

        return <<<JS
            const { livewireId, editorId, markdown } = \$event.detail;
            if (\$el.id !== editorId || \$el.closest('[wire\\\\:id]')?.getAttribute('wire:id') !== livewireId) return;
            const bookmark = \$el._flexMediaStudioBookmark;
            delete \$el._flexMediaStudioBookmark;
            const cm = \$el._editor?.codemirror;
            if (!cm || !bookmark || cm !== bookmark.editor || cm.getOption('readOnly') || !cm.isClean(bookmark.generation)) {
                if (window.FilamentNotification) new window.FilamentNotification().title({$unavailable}).warning().send();
                return;
            }
            cm.replaceRange(markdown, bookmark.from, bookmark.to, 'flex-media-studio');
            JS;
    }
}
