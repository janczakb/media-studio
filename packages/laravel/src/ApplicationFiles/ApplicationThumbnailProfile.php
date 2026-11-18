<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\ApplicationFiles;

use Illuminate\Support\Facades\Schema;
use LogicException;
use UseFlex\MediaCore\Images\ImagePreset;

final readonly class ApplicationThumbnailProfile
{
    private function __construct(
        public string $disk,
        public string $prefix,
        public ImagePreset $preset,
        public int $sourceBytes,
        public int $sourcePixels,
        public int $outputBytes,
        public int $budgetBytes,
        public int $budgetEntries,
        public int $maxPending,
        public int $ttlMinutes,
        public string $driver,
        public ?string $connection,
        public string $queue,
    ) {}

    public static function configured(): ?self
    {
        $config = (array) config('media-studio.application_files.thumbnails');
        if (($config['enabled'] ?? false) !== true || ! Schema::hasTable('fms_application_thumbnails')) {
            return null;
        }
        $disk = $config['disk'] ?? 'local';
        $prefix = $config['prefix'] ?? 'flex-media-studio/application-thumbnails';
        $driver = $config['driver'] ?? config('media-studio.processing.native.driver', 'auto');
        if (! is_string($disk) || ! is_array(config('filesystems.disks.'.$disk))
            || ! is_string($prefix) || strlen($prefix) > 100 || ! preg_match('~\A[a-zA-Z0-9_-]+(?:/[a-zA-Z0-9_-]+)*\z~', $prefix)
            || ! in_array($driver, ['auto', 'gd', 'imagick'], true)) {
            throw new LogicException('Configure a valid application thumbnail disk, prefix and renderer.');
        }
        $edge = max(64, min(800, (int) ($config['edge'] ?? 400)));

        return new self($disk, $prefix, new ImagePreset('application-thumbnail', [
            'width' => $edge, 'height' => $edge, 'fit' => 'contain', 'format' => $config['format'] ?? 'avif',
            'quality' => $config['quality'] ?? 40, 'avif_effort' => $config['avif_effort'] ?? 3, 'upscale' => false,
            'background' => '#ffffff', 'position' => 'center', 'strip_metadata' => true,
        ]), max(1, min(52428800, (int) ($config['max_source_bytes'] ?? 12582912))),
            max(1, min(40000000, (int) ($config['max_source_pixels'] ?? 16000000))),
            max(1, min(1048576, (int) ($config['max_output_bytes'] ?? 262144))),
            max(0, (int) ($config['max_cache_bytes'] ?? 1073741824)),
            max(0, min(1000000, (int) ($config['max_cache_entries'] ?? 25000))),
            max(1, min(1000, (int) ($config['max_pending'] ?? 128))),
            max(5, min(43200, (int) ($config['ttl_minutes'] ?? 1440))),
            $driver, $config['connection'] ?? null, $config['queue'] ?? 'media-application-thumbnails');
    }

    public function identity(): string
    {
        $storage = array_intersect_key(config('filesystems.disks.'.$this->disk), array_flip(['driver', 'root', 'bucket', 'endpoint', 'region']));

        return hash('sha256', json_encode([$this->disk, $this->prefix, $storage, $this->preset->hash(), $this->driver, $this->sourceBytes, $this->sourcePixels, $this->outputBytes], JSON_THROW_ON_ERROR));
    }

    public function usesWorker(): bool
    {
        $name = $this->connection ?? config('queue.default');

        return in_array(config('queue.connections.'.$name.'.driver'), ['database', 'redis', 'sqs', 'beanstalkd'], true);
    }
}
