<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    @php($files = $getSelectedStudioMedia())
    <div id="{{ $getId() }}" class="fms-filament-picker"
        x-data="{
            layout: @js($getStudioLayout()),
            dragged: null,
            target: null,
            move(from, to) {
                if (from !== to && to >= 0 && to < {{ count($files) }}) {
                    $wire.callSchemaComponentMethod(@js($getKey()), 'moveStudioMedia', [from, to - from])
                }
            },
        }"
        data-layout="{{ $getStudioLayout() }}" x-bind:data-layout="layout">
        @if ($files === [])
            <div class="fms-filament-picker__empty">
                <svg class="fms-filament-picker__frame" aria-hidden="true" focusable="false"><rect x="1" y="1" /></svg>
                <div class="fms-filament-picker__source-header">
                    @include('media-studio::filament.picker-art')
                    <strong class="fms-filament-picker__source-title">{{ __('media-studio::editor.pick_from_library') }}</strong>
                    <p class="fms-filament-picker__source-description">{{ __('media-studio::editor.picker_hint') }}</p>
                </div>
                @unless ($isDisabled())
                    <div class="fms-filament-picker__source-controls">{{ $getAction('pickStudioMedia') }}</div>
                @endunless
            </div>
        @else
            @if ($hasStudioLayoutSwitcher())
            <div class="fms-filament-picker__layouts" role="group" aria-label="{{ $getLabel() }}">
                <span class="fms-filament-picker__layout-indicator" aria-hidden="true"></span>
                @foreach (['list', 'grid'] as $layout)
                    <button type="button" class="fms-filament-picker__action"
                        x-on:click="layout = @js($layout)" x-bind:aria-pressed="layout === @js($layout)"
                        aria-label="{{ __('media-studio::editor.'.$layout.'_view') }}" title="{{ __('media-studio::editor.'.$layout.'_view') }}">
                        @include('media-studio::filament.media-picker-icon', ['icon' => $layout])
                    </button>
                @endforeach
            </div>
            @endif
            <ul class="fms-filament-picker__list" aria-label="{{ $getLabel() }}" x-on:dragend="dragged = target = null">
                @foreach ($files as $index => $file)
                    @php($image = $file ? \UseFlex\MediaStudio\Integrations\Filament\StudioMediaDisplay::image($file) : null)
                    @php($fileActions = $file ? $getStudioFileActions($index) : [])
                    @php($name = $file['name'] ?? __('media-studio::editor.file_unavailable'))
                    <li @class(['fms-filament-picker__row', 'has-preview' => $image])
                        wire:key="{{ $getId() }}-{{ $file['assetId'] ?? 'unavailable' }}-{{ $file['versionId'] ?? $index }}"
                        title="{{ $name }}"
                        @if (!$isDisabled() && $isStudioReorderable())
                            x-bind:data-drop-target="target === {{ $index }} && dragged !== {{ $index }} ? '' : null"
                            x-on:dragover="if (dragged !== null) { $event.preventDefault(); target = {{ $index }} }"
                            x-on:drop="if (dragged !== null) { $event.preventDefault(); move(dragged, {{ $index }}); dragged = target = null }"
                        @endif>
                        <span @class(['fms-filament-picker__art', 'is-circular' => $isStudioCircular()])>
                            <img src="{{ $image ?? \UseFlex\MediaStudio\Integrations\Filament\StudioMediaDisplay::pickerIcon($file) }}"
                                alt="" loading="lazy" decoding="async"
                                x-on:error.once="$el.src = @js(\UseFlex\MediaStudio\Integrations\Filament\StudioMediaDisplay::pickerIcon($file)); $el.classList.add('is-glyph')"
                                @class(['is-glyph' => !$image])>
                        </span>
                        <div class="fms-filament-picker__copy">
                            <span class="fms-filament-picker__name">{{ $name }}</span>
                            @if ($file)
                                <span class="fms-filament-picker__meta">{{ \Illuminate\Support\Number::fileSize($file['sizeBytes']) }}</span>
                            @endif
                        </div>
                        @if (($file && $isStudioDownloadable()) || !$isDisabled() || $fileActions !== [])
                            <div class="fms-filament-picker__actions">
                                @foreach ($fileActions as $fileAction)
                                    {{ $fileAction }}
                                @endforeach
                                @if ($file && $isStudioDownloadable())
                                    <a class="fms-filament-picker__action" href="{{ $file['url'] }}?download=1" download
                                        aria-label="{{ __('media-studio::editor.download_file') }}" title="{{ __('media-studio::editor.download_file') }}">
                                        @include('media-studio::filament.media-picker-icon', ['icon' => 'download'])
                                    </a>
                                @endif
                                @unless ($isDisabled())
                                    @if ($isStudioReorderable() && count($files) > 1)
                                        <button type="button" class="fms-filament-picker__action fms-filament-picker__reorder"
                                            aria-label="{{ __('media-studio::editor.reorder_file') }}: {{ $name }}"
                                            title="{{ __('media-studio::editor.reorder_file') }}" aria-keyshortcuts="ArrowUp ArrowDown ArrowLeft ArrowRight"
                                            draggable="true" wire:loading.attr="disabled" wire:target="callSchemaComponentMethod"
                                            x-on:dragstart="dragged = {{ $index }}; $event.dataTransfer.effectAllowed = 'move'; $event.dataTransfer.setData('text/plain', '')"
                                            x-on:keydown.up.prevent="move({{ $index }}, {{ $index - 1 }})"
                                            x-on:keydown.left.prevent="move({{ $index }}, {{ $index - 1 }})"
                                            x-on:keydown.down.prevent="move({{ $index }}, {{ $index + 1 }})"
                                            x-on:keydown.right.prevent="move({{ $index }}, {{ $index + 1 }})">
                                            @include('media-studio::filament.media-picker-icon', ['icon' => 'reorder'])
                                        </button>
                                    @endif
                                    <button type="button" class="fms-filament-picker__action fms-filament-picker__remove"
                                        aria-label="{{ __('media-studio::editor.remove_file') }}" title="{{ __('media-studio::editor.remove_file') }}"
                                        wire:loading.attr="disabled" wire:target="callSchemaComponentMethod"
                                        wire:click="callSchemaComponentMethod(@js($getKey()), 'removeStudioMedia', [{{ $index }}])">
                                        @include('media-studio::filament.media-picker-icon', ['icon' => 'remove'])
                                    </button>
                                @endunless
                            </div>
                        @endif
                    </li>
                @endforeach
            </ul>
            @unless ($isDisabled())
                <div class="fms-filament-picker__footer">{{ $getAction('pickStudioMedia') }}</div>
            @endunless
        @endif
    </div>
</x-dynamic-component>
