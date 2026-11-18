<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Tables\Columns\ImageColumn;

final class StudioMediaColumn extends ImageColumn
{
    use DisplaysStudioMedia;

    protected function loadStudioFiles(): array
    {
        $originalRecord = $this->record;
        $originalKey = $this->getRecordKey();
        $values = [];
        try {
            foreach ($this->getTable()->getRecords() as $key => $record) {
                $this->record($record)->recordKey((string) $key);
                $workspace = $this->studioWorkspace();
                if ($workspace === null) {
                    continue;
                }
                foreach ($this->studioValues() as $value) {
                    if (is_string($value)) {
                        $values[$workspace][$value] = $value;
                    }
                }
            }
        } finally {
            $this->record = $originalRecord;
            $this->recordKey($originalKey);
        }

        $resolver = app(StudioMediaDisplay::class);

        $files = [];
        foreach ($values as $workspace => $references) {
            $files[$workspace] = $resolver->resolve($workspace, $references);
        }

        return $files;
    }
}
