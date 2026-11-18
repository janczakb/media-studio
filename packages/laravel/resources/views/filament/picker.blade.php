<x-dynamic-component :component="$getFieldWrapperView()" :field="$field">
    <div
        data-fms-filament-picker
        wire:ignore
        x-data="{
            options: @js($getStudioOptions()),
            selection: $wire.$entangle(@js($getStatePath())),
            studio: null,
            disposed: false,
            failed: false,
            async init() {
                try {
                    const { moduleUrl, baseUrl, selectionLimit, multipleSelection, selectionMessage, ...options } = this.options;
                    const { mountMediaStudio, createHttpTransport } = await import(moduleUrl);
                    if (this.disposed) return;

                    const theme = document.documentElement.classList.contains('dark') ? 'dark' : 'light';
                    this.studio = mountMediaStudio(this.$refs.studio, {
                        ...options,
                        theme,
                        mode: 'picker',
                        transport: createHttpTransport({
                            baseUrl,
                            csrfToken: () => document.querySelector('meta[name=csrf-token]')?.content ?? @js(csrf_token()),
                        }),
                        pickerSelectionLimit: multipleSelection ? selectionLimit : 1,
                        pickerMultipleSelection: !!multipleSelection,
                        onPickerSelectionChange: references => {
                            this.selection = multipleSelection ? references : (references[0] ?? null);
                        },
                    });
                } catch {
                    if (!this.disposed) this.failed = true;
                }
            },
            destroy() {
                this.disposed = true;
                this.studio?.destroy();
            },
        }"
    >
        <div x-ref="studio" class="fms-filament-picker__viewport"></div>
        <p x-show="!options.multipleSelection && selection && !failed" x-cloak role="status">{{ $getStudioOptions()['selectionMessage'] ?? __('media-studio::editor.selected') }}</p>
        <p x-show="failed" x-cloak role="alert">{{ __('media-studio::editor.load_failed') }}</p>
    </div>
</x-dynamic-component>
