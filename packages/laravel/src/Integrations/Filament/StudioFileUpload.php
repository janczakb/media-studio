<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Forms\Components\FileUpload;
use UseFlex\MediaStudio\Integrations\Filament\Concerns\InteractsWithStudioUploads;

final class StudioFileUpload extends FileUpload
{
    use InteractsWithStudioUploads;
}
