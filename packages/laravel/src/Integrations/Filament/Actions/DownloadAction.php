<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Icons\Heroicon;
use UseFlex\MediaStudio\Library\MediaReferences;

final class DownloadAction extends Action
{
    use InteractsWithStudio;

    public static function getDefaultName(): string
    {
        return 'studioDownload';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('media-studio::actions.download'))
            ->icon(Heroicon::OutlinedArrowDownTray)
            ->action(function (): void {
                $principal = $this->studioPrincipal();
                $entry = $this->entryIdentity($this->getRecord());
                $reference = app(MediaReferences::class)->resolve($principal, $this->workspaceId(), [['assetId' => $entry['id']]])[0];

                $this->redirect($reference['url'].'?download=1', navigate: false);
            });
    }
}
