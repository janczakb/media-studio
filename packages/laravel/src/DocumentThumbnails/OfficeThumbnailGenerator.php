<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\DocumentThumbnails;

use RuntimeException;
use UseFlex\MediaCore\Process\ProcessExecutor;

/** Deploy only inside a network-isolated, resource-limited conversion worker. */
final class OfficeThumbnailGenerator implements ThumbnailGenerator
{
    public function rasterize(string $sourcePath, array $profile): mixed
    {
        if (! config('media-studio.processing.document_thumbnails.office_isolated_worker', false)) {
            throw new RuntimeException('document_isolation_required');
        }
        if (! class_exists(\ZipArchive::class)) {
            throw new RuntimeException('document_zip_unavailable');
        }
        $zip = new \ZipArchive;
        if ($zip->open($sourcePath) !== true) {
            throw new RuntimeException('document_archive_invalid');
        }
        try {
            if ($zip->numFiles > 4096) {
                throw new RuntimeException('document_archive_too_large');
            }
            $expanded = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $stat = $zip->statIndex($i);
                if (! $stat) {
                    throw new RuntimeException('document_archive_invalid');
                }
                $expanded += $stat['size'];
                if ($expanded > 104857600 || $stat['size'] > 33554432) {
                    throw new RuntimeException('document_archive_too_large');
                }
                if (preg_match('~(?:vbaProject|/embeddings/|/Scripts/|Basic/|\.bin$)~i', $stat['name'])) {
                    throw new RuntimeException('document_active_content');
                }
                if (preg_match('~\.(?:xml|rels)$~i', $stat['name'])) {
                    if ($stat['size'] > 8388608) {
                        throw new RuntimeException('document_archive_too_large');
                    }
                    $xml = $zip->getFromIndex($i);
                    if ($xml === false || preg_match('~<!DOCTYPE|<!ENTITY|TargetMode\s*=\s*["\']External|(?:href|src)\s*=\s*["\'](?:https?:|file:|ftp:)~i', $xml)) {
                        throw new RuntimeException('document_external_content');
                    }
                }
            }
        } finally {
            $zip->close();
        }
        $directory = sys_get_temp_dir().'/fms-office-'.bin2hex(random_bytes(16));
        if (! mkdir($directory, 0700)) {
            throw new RuntimeException('document_temp_failed');
        }
        try {
            $extension = $profile['source_extension'] ?? '';
            if (! in_array($extension, ['docx', 'xlsx', 'pptx', 'odt', 'ods', 'odp'], true)) {
                throw new RuntimeException('document_format_unsupported');
            }
            if (! copy($sourcePath, $directory.'/source.'.$extension)) {
                throw new RuntimeException('document_temp_failed');
            }
            $binary = config('media-studio.processing.document_thumbnails.office_binary', 'soffice');
            app(ProcessExecutor::class)->writeFile(
                [$binary, '-env:UserInstallation=file://'.$directory.'/profile', '--headless', '--nologo', '--nodefault', '--nofirststartwizard', '--convert-to', 'pdf', '--outdir', $directory, $directory.'/source.'.$extension],
                $profile['timeout'],
                $directory.'/source.pdf',
                33554432,
                'document_render_failed',
            );

            return app(PdfThumbnailGenerator::class)->rasterize($directory.'/source.pdf', $profile);
        } finally {
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
            foreach ($iterator as $file) {
                $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
            }
            @rmdir($directory);
        }
    }
}
