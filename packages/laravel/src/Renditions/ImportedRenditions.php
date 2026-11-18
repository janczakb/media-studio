<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Renditions;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaCore\Storage\StreamObjectStore;
use UseFlex\MediaStudio\Uploads\AcceptedFiles;
use UseFlex\MediaStudio\Uploads\UploadAttempts;

final readonly class ImportedRenditions
{
    public const MAX_FILES = 12;

    private function __construct(private array $items, private array $roles, private array $sourceDimensions) {}

    /** @param array<string, UploadedFile> $files */
    public static function prepare(object $workspace, UploadedFile $source, array $files, array $roles): self
    {
        abort_unless(count($files) >= 1 && count($files) <= self::MAX_FILES, 422);
        abort_if(array_diff(array_keys($roles), ['thumbnail', 'preview']) !== [], 422);
        $total = $source->getSize();
        $items = [];
        $sourceDimensions = self::image($workspace, $source);
        foreach ($files as $name => $file) {
            abort_unless(is_string($name) && preg_match('/\A[a-z][a-z0-9_-]{0,22}\z/', $name), 422, 'Invalid conversion name.');
            $image = self::image($workspace, $file);
            $total += $image['size_bytes'];
            abort_if($total > (int) config('media-studio.upload.max_kilobytes', 25600) * 1024, 422, 'The complete image bundle exceeds the upload limit.');
            $items['import-'.$name] = ['file' => $file, 'info' => [...$image, 'sha256' => hash_file('sha256', $file->getPathname())]];
        }
        ksort($items);
        foreach ($roles as &$name) {
            abort_unless(is_string($name) && isset($items['import-'.$name]), 422, 'The display role must reference an uploaded conversion.');
            $name = 'import-'.$name;
        }
        unset($name);
        ksort($roles);

        return new self($items, $roles, $sourceDimensions);
    }

    private static function image(object $workspace, UploadedFile $file): array
    {
        $image = @getimagesize($file->getPathname());
        abort_unless(is_array($image) && in_array($image['mime'], Renditions::IMAGE_MIMES, true)
            && $file->getMimeType() === $image['mime'], 422, 'The bundle must contain valid supported images.');
        AcceptedFiles::assertAllowed($workspace, $file->getClientOriginalName(), $image['mime']);
        abort_unless($image[0] > 0 && $image[1] > 0 && $image[0] <= intdiv(40_000_000, $image[1]), 422, 'Image exceeds the pixel limit.');

        return ['width' => $image[0], 'height' => $image[1], 'mime_type' => $image['mime'], 'size_bytes' => $file->getSize()];
    }

    public function checkPolicy(object $workspace): void
    {
        foreach ($this->items as $item) {
            AcceptedFiles::assertAllowed($workspace, $item['file']->getClientOriginalName(), $item['info']['mime_type']);
        }
    }

    public function presets(): array
    {
        return array_keys($this->items);
    }

    public function fingerprint(): array
    {
        return [$this->roles, array_map(static fn (array $item) => $item['info'], $this->items)];
    }

    public function write(object $attempt, StreamObjectStore $store): void
    {
        foreach ($this->items as $preset => $item) {
            $stream = fopen($item['file']->getPathname(), 'rb');
            try {
                $store->write(UploadAttempts::variantKey($attempt, $preset), $stream);
            } finally {
                if (is_resource($stream)) {
                    fclose($stream);
                }
            }
        }
    }

    public function publish(object $attempt, object $entry): void
    {
        $rows = [];
        foreach ($this->items as $preset => $item) {
            $rows[$preset] = [...$item['info'], 'entry_id' => $entry->id, 'version_id' => $attempt->id,
                'preset' => $preset, 'state' => 'ready', 'owned' => true, 'disk' => $attempt->disk,
                'object_key' => UploadAttempts::variantKey($attempt, $preset), 'created_at' => now(), 'updated_at' => now()];
        }
        DB::table('fms_renditions')->insert(array_values($rows));
        DB::table('fms_entries')->where('id', $entry->id)->update([
            'width' => $this->sourceDimensions['width'],
            'height' => $this->sourceDimensions['height'],
        ]);
        foreach ($this->roles as $role => $preset) {
            app(Renditions::class)->projectRole($entry->id, $attempt->id, $role, 'ready', (object) $rows[$preset]);
        }
    }
}
