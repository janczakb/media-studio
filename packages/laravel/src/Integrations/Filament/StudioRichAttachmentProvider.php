<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament;

use Filament\Forms\Components\RichEditor\FileAttachmentProviders\Contracts\FileAttachmentProvider;
use Filament\Forms\Components\RichEditor\RichContentAttribute;
use Illuminate\Support\Str;
use Livewire\Features\SupportFileUploads\TemporaryUploadedFile;
use UseFlex\MediaStudio\Http\ApiUrl;

final readonly class StudioRichAttachmentProvider implements FileAttachmentProvider
{
    public function __construct(private string|array $workspaces) {}

    public function attribute(RichContentAttribute $attribute): static
    {
        return $this;
    }

    public function getFileAttachmentUrl(mixed $file): ?string
    {
        if (! is_string($file) || strlen($file) > 146) {
            return null;
        }
        $parts = explode(':', $file, 5);
        if (count($parts) < 3 || count($parts) > 4) {
            return null;
        }
        [$workspace, $asset, $version] = $parts;
        $variant = $parts[3] ?? null;
        if (! Str::isUlid($workspace) || ! Str::isUlid($asset) || ! Str::isUlid($version)
            || ! in_array($workspace, (array) $this->workspaces, true)
            || ($variant !== null && ! preg_match('/\A[a-zA-Z][a-zA-Z0-9_-]{0,63}\z/', $variant))) {
            return null;
        }

        // Content routes reauthorize every read against the active tenant, workspace and exact asset version.
        return ApiUrl::to("workspaces/{$workspace}/entries/{$asset}/versions/{$version}/".($variant === null ? 'content' : 'renditions/'.$variant));
    }

    public function saveUploadedFileAttachment(TemporaryUploadedFile $file): mixed
    {
        abort(422, 'Attach files using the Media Studio picker.');
    }

    public function getDefaultFileAttachmentVisibility(): ?string
    {
        return 'private';
    }

    public function isExistingRecordRequiredToSaveNewFileAttachments(): bool
    {
        return false;
    }

    public function cleanUpFileAttachments(array $exceptIds): void
    {
        // The owning application may remove its references. Studio assets can have other owners.
    }
}
