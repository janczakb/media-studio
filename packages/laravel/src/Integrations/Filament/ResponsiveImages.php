<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Tiptap\Core\Extension;

final class ResponsiveImages extends Extension
{
    public static $name = 'studioResponsiveImages';

    public function addGlobalAttributes(): array
    {
        return [['types' => ['image'], 'attributes' => [
            'srcset' => ['default' => null],
            'sizes' => ['default' => null],
        ]]];
    }
}
