<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Library\MediaReferences;

final class StudioMediaDisplay
{
    /** @return array<string, array> Assets keyed by the requested pinned reference. */
    public function resolve(string $workspace, array $values, ?string $thumbnailPreset = null): array
    {
        $references = [];
        foreach ($values as $value) {
            if (is_string($value) && ($reference = StudioReference::parse($value)) !== null) {
                $references[$value] = $reference;
            }
        }
        if ($references === []) {
            return [];
        }

        try {
            $principal = StudioPicker::principal();
            $resolver = app(MediaReferences::class);
            $files = [];
            foreach (array_chunk($references, 120) as $batch) {
                foreach ($resolver->resolve($principal, $workspace, $batch, availableOnly: true, presentation: true, thumbnailPreset: $thumbnailPreset) as $file) {
                    $files[StudioReference::key($file)] = $file;
                }
            }

            return $files;
        } catch (HttpExceptionInterface $error) {
            if (! in_array($error->getStatusCode(), [403, 404], true)) {
                throw $error;
            }

            return [];
        }
    }

    public static function image(array $file): ?string
    {
        return $file['thumbnailUrl'] ?? null;
    }

    public static function pickerIcon(?array $file): string
    {
        $mime = $file['mimeType'] ?? '';
        $kind = match (strtok($mime, '/')) {
            'image' => 'image',
            'audio' => 'audio',
            'video' => 'video',
            default => match (strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION))) {
                'pdf', 'doc', 'docx', 'odt', 'rtf', 'txt' => 'document',
                'xls', 'xlsx', 'ods', 'csv' => 'sheet',
                'ppt', 'pptx', 'odp' => 'presentation',
                'html', 'htm' => 'html',
                'json', 'xml', 'js', 'ts', 'css', 'php', 'py' => 'code',
                'fig' => 'figma',
                'sketch' => 'sketch',
                default => $mime === 'application/pdf' ? 'document' : 'attachment',
            },
        };

        static $icons = [];

        return $icons[$kind] ??= 'data:image/svg+xml;base64,'.base64_encode(
            file_get_contents(__DIR__.'/../../../resources/images/picker/color-'.$kind.'.svg'),
        );
    }
}
