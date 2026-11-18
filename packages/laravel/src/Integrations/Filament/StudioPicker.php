<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Facades\Filament;
use Filament\Forms\Components\Field;
use Filament\Forms\Components\Select;
use Filament\Schemas\Components\Utilities\Get;
use Filament\Schemas\Components\Utilities\Set;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use UseFlex\MediaStudio\Http\ApiUrl;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\MediaReferences;
use UseFlex\MediaStudio\Renditions\Renditions;

final class StudioPicker extends Field
{
    protected string $view = 'media-studio::filament.picker';

    public const INLINE_IMAGE_MIMES = ['image/jpeg', 'image/png', 'image/webp', 'image/avif', 'image/gif'];

    public const MIME_PATTERN = '/\A[a-z0-9][a-z0-9.+_-]{0,63}\/(?:[a-z0-9][a-z0-9.+_-]{0,95}|\*)\z/';

    private array $studioOptions = [];

    /** Serializable presentation options for the shared React picker. */
    public function studioOptions(array $options): static
    {
        $allowed = array_fill_keys(['layout', 'locale', 'localization', 'browse', 'comments',
            'pdfPreview', 'documentPreview', 'imagePreview', 'videoEditing', 'previewActions',
            'previewMenu', 'previewTitleEditable', 'videoPreviewSkin', 'primaryColor', 'branding'], true);
        if (array_diff_key($options, $allowed) !== []) {
            throw new InvalidArgumentException('Use the picker methods for workspace, folder, MIME, upload and selection restrictions.');
        }
        $this->studioOptions = array_replace($this->studioOptions, $options);

        return $this;
    }

    /** @param list<string>|null $types */
    public function acceptedMimeTypes(?array $types): static
    {
        $this->studioOptions['pickerMimeTypes'] = self::normalizeMimeTypes($types);

        return $this;
    }

    public function allowUpload(bool $enabled = true): static
    {
        $this->studioOptions['upload'] = ['enabled' => $enabled, 'allowFolders' => false];

        return $this;
    }

    public function folders(?string $default = null, ?string $scope = null): static
    {
        if (($default !== null && ! Str::isUlid($default)) || ($scope !== null && ! Str::isUlid($scope))) {
            throw new InvalidArgumentException('Studio folders require valid ULIDs.');
        }
        $this->studioOptions['pickerInitialFolder'] = $default;
        $this->studioOptions['pickerScopedFolder'] = $scope;

        return $this;
    }

    public function selectionLimit(int $max): static
    {
        $this->studioOptions['selectionLimit'] = max(1, min(120, $max));
        $this->studioOptions['multipleSelection'] = true;

        return $this;
    }

    public static function validateSelectionLimits(int $minimum, int $maximum): void
    {
        if ($minimum < 0 || $maximum < 1 || $maximum > 120 || $minimum > $maximum) {
            throw new InvalidArgumentException('Studio selection requires 0 <= minimum <= maximum <= 120 and maximum >= 1.');
        }
    }

    /** Existing selection is editable in the modal and counts toward its limit. */
    public function selectedReferences(array $references): static
    {
        Validator::make(['references' => $references], [
            'references' => ['array', 'list', 'max:120'],
            'references.*' => ['array:assetId,versionId,workspaceId'],
            'references.*.assetId' => ['required', 'ulid'],
            'references.*.versionId' => ['required', 'ulid'],
            'references.*.workspaceId' => ['nullable', 'ulid'],
        ])->validate();
        $this->studioOptions['pickerInitialSelection'] = $references;
        $this->default($references);

        return $this;
    }

    public function thumbnailPreset(?string $preset): static
    {
        \UseFlex\MediaStudio\Library\ThumbnailProjection::validatePreset($preset);
        $this->studioOptions['pickerThumbnailPreset'] = $preset;

        return $this;
    }

    public function selectionMessage(string $message): static
    {
        $this->studioOptions['selectionMessage'] = $message;

        return $this;
    }

    /** @return list<string>|null */
    public static function normalizeMimeTypes(?array $types): ?array
    {
        if ($types === null) {
            return null;
        }
        if ($types === [] || count($types) > 32) {
            throw new InvalidArgumentException('Configure between 1 and 32 MIME types (exact or category/*), or null for all types.');
        }

        $normalized = [];
        foreach ($types as $type) {
            if (! is_string($type) || ! preg_match(self::MIME_PATTERN, strtolower(trim($type)))) {
                throw new InvalidArgumentException('Invalid MIME type.');
            }
            $normalized[] = strtolower(trim($type));
        }

        return array_values(array_unique($normalized));
    }

    public static function matchesMime(string $mime, ?array $types): bool
    {
        if ($types === null) {
            return true;
        }
        foreach ($types as $type) {
            if ($type === $mime || (str_ends_with($type, '/*') && str_starts_with($mime, substr($type, 0, -1)))) {
                return true;
            }
        }

        return false;
    }

    public function studio(string|array $workspace, string $moduleUrl, ?string $stylesheetUrl = null): static
    {
        $scopes = is_array($workspace) ? array_values(array_unique($workspace)) : [$workspace];
        if ($scopes === [] || count($scopes) > 20 || count(array_filter($scopes, fn ($id): bool => is_string($id) && Str::isUlid($id))) !== count($scopes)) {
            throw new InvalidArgumentException('A Studio workspace ULID is required.');
        }

        $this->studioOptions = [
            ...$this->studioOptions,
            ...(is_array($workspace) ? ['workspaceIds' => $scopes] : ['workspaceId' => $workspace]),
            'moduleUrl' => $moduleUrl,
            'stylesheetUrl' => $stylesheetUrl,
            'baseUrl' => ApiUrl::base(),
            'locale' => app()->getLocale() === 'pl' ? 'pl' : 'en',
        ];

        return $this;
    }

    public static function resolveSelection(string|array $workspace, array $data, ?array $acceptedMimeTypes = null): array
    {
        $workspace = self::selectedWorkspace($workspace, $data);
        $principal = self::principal();

        $reference = Validator::make($data, [
            'reference' => ['required', 'array:assetId,versionId,rendition,workspaceId'],
            'reference.workspaceId' => ['nullable', 'ulid'],
            'reference.assetId' => ['required', 'ulid'],
            'reference.versionId' => ['required', 'ulid'],
            'reference.rendition' => ['nullable', 'string', 'regex:/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/'],
        ])->validate()['reference'];

        $variant = Validator::make($data, ['rendition' => ['nullable', 'string', 'regex:/\A(?:__original|[a-zA-Z][a-zA-Z0-9_-]{0,63})\z/']])->validate()['rendition'] ?? null;
        if ($variant === '__original') {
            unset($reference['rendition']);
        } elseif ($variant !== null) {
            $reference['rendition'] = $variant;
        }

        $references = [$reference];
        if ($acceptedMimeTypes !== null && isset($reference['rendition'])) {
            $references[] = array_diff_key($reference, ['rendition' => true]);
        }
        $resolved = app(MediaReferences::class)->resolve($principal, $workspace, $references, presentation: true);
        foreach ($resolved as $file) {
            abort_if($acceptedMimeTypes !== null && ! self::matchesMime($file['mimeType'], $acceptedMimeTypes), 422, __('media-studio::editor.unsupported_type'));
        }

        return $resolved[0];
    }

    public function suggestEditorAlt(string|array $workspace): static
    {
        return $this->afterStateUpdated(function (mixed $state, Get $get, Set $set) use ($workspace): void {
            if (! is_array($state) || ! isset($state['assetId'], $state['versionId']) || filled($get('alt'))) {
                return;
            }

            try {
                $file = self::resolveSelection($workspace, ['reference' => $state, 'rendition' => '__original']);
                if (self::isInlineImage($file['mimeType']) && filled($file['alt'])) {
                    $set('alt', $file['alt']);
                }
            } catch (HttpExceptionInterface $error) {
                if (! in_array($error->getStatusCode(), [403, 404, 422], true)) {
                    throw $error;
                }
            }
        });
    }

    public static function selectedWorkspace(string|array $scope, array $data): string
    {
        $allowed = is_array($scope) ? $scope : [$scope];
        $selected = $data['reference']['workspaceId'] ?? (count($allowed) === 1 ? $allowed[0] : null);
        abort_unless(is_string($selected) && in_array($selected, $allowed, true), 422, 'Select a file from an allowed workspace.');

        return $selected;
    }

    /** Ready variants only; permission and external-provider checks run through the same resolver. */
    public static function responsiveAttributes(string|array $workspace, array $data, array $file, string $sizes = '(max-width: 768px) 100vw, 1000px'): array
    {
        $workspace = self::selectedWorkspace($workspace, $data);
        $rows = Renditions::published()->where('version_id', $file['versionId'])
            ->whereIn('mime_type', Renditions::IMAGE_MIMES)->where('width', '>', 0)
            ->orderByDesc('width')->limit(64)->get(['preset', 'width', 'height', 'mime_type']);
        if ($rows->isEmpty()) {
            return [];
        }
        // A srcset has one encoding. The largest variant remains the ordinary img fallback.
        $mime = $rows->contains('mime_type', $file['mimeType']) ? $file['mimeType'] : $rows->first()->mime_type;
        $rows = $rows->where('mime_type', $mime)->where('height', '>', 0);
        $geometry = isset($file['rendition']) ? $rows->firstWhere('preset', $file['rendition']) : $rows->first();
        if ($geometry === null) {
            return [];
        }
        $rows = $rows->filter(fn ($row): bool => abs($row->width * $geometry->height - $row->height * $geometry->width) <= max($geometry->width, $geometry->height) * 2)->unique('width')->values();
        $references = $rows->map(fn ($row): array => [
            'assetId' => $file['assetId'], 'versionId' => $file['versionId'], 'rendition' => $row->preset,
        ])->all();
        $resolved = app(MediaReferences::class)->resolve(self::principal(), $workspace, $references);
        $candidates = $rows->map(fn ($row, int $index): array => [
            'url' => $resolved[$index]['url'], 'width' => (int) $row->width,
        ])->all();
        if (count($candidates) < 2) {
            return [];
        }

        return [
            'src' => $candidates[0]['url'],
            'srcset' => implode(', ', array_map(fn (array $item): string => $item['url'].' '.$item['width'].'w', $candidates)),
            'sizes' => $sizes,
        ];
    }

    public static function renditionField(string|array $workspace, ?array $acceptedMimeTypes = null): Select
    {
        return Select::make('rendition')
            ->label(__('media-studio::editor.rendition'))
            ->default('__original')
            ->native(false)
            ->options(function (Get $get) use ($workspace, $acceptedMimeTypes): array {
                $options = ['__original' => __('media-studio::editor.original')];
                $reference = $get('reference');
                if (! is_array($reference) || empty($reference['assetId']) || empty($reference['versionId'])) {
                    return $options;
                }
                $file = self::resolveSelection($workspace, ['reference' => $reference, 'rendition' => '__original']);
                if (! self::isInlineImage($file['mimeType']) || ($acceptedMimeTypes !== null && ! self::matchesMime($file['mimeType'], $acceptedMimeTypes))) {
                    return $options;
                }
                $rows = Renditions::published()->where('version_id', $file['versionId'])
                    ->whereIn('mime_type', Renditions::IMAGE_MIMES)->when($acceptedMimeTypes !== null, fn ($query) => $query->whereIn('mime_type', $acceptedMimeTypes))->orderBy('preset')->limit(64)->get(['preset', 'width', 'height']);
                foreach ($rows as $row) {
                    $dimensions = $row->width && $row->height ? ' · '.$row->width.' × '.$row->height : '';
                    $options[$row->preset] = $row->preset.$dimensions;
                }

                return $options;
            });
    }

    public static function isInlineImage(string $mime): bool
    {
        return in_array($mime, self::INLINE_IMAGE_MIMES, true);
    }

    public static function principal(): Principal
    {
        $panel = app()->bound('filament') ? Filament::getCurrentPanel() : null;
        $actor = $panel === null ? auth()->user() : $panel->auth()->user();
        abort_unless($actor instanceof Authenticatable, 403);

        return Principal::fromUser($actor);
    }

    public function getStudioOptions(): array
    {
        return ['primaryColor' => config('media-studio.appearance.primary_color', '#0866ff'), ...$this->studioOptions];
    }
}
