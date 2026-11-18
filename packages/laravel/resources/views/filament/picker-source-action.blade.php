{{-- Same button geometry as FLEX upload; Filament retains action/modal ownership. --}}
<button type="button"
    {{ $action->getExtraAttributeBag()->class(['fms-filament-picker-trigger']) }}
    wire:click="{{ $action->getLivewireClickHandler() }}"
    wire:loading.attr="disabled"
    wire:target="{{ $action->getLivewireClickHandler() }}"
    @disabled($action->isDisabled())
>
    {{ $action->getLabel() }}
</button>
