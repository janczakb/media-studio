<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Infolists\Components\ImageEntry;

final class StudioMediaEntry extends ImageEntry
{
    use DisplaysStudioMedia;
}
