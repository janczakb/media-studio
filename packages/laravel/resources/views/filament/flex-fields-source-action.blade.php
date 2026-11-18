{{-- Native action lifecycle with the installed FLEX upload button presentation. --}}
<button type="button"
    {{ $action->getExtraAttributeBag()->class(['fff-flex-file-upload__select']) }}
    wire:click="{{ $action->getLivewireClickHandler() }}"
    wire:loading.attr="disabled"
    wire:target="{{ $action->getLivewireClickHandler() }}"
    @disabled($action->isDisabled())
>
    {{ $action->getLabel() }}
</button>
