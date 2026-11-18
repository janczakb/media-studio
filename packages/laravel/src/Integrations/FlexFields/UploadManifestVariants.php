<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\FlexFields;

use Bjanczak\FilamentFlexFields\Support\FileUpload\Media\Storage\UploadManifestRepository;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaReceipt;
use Bjanczak\FilamentFlexFields\Support\Media\Data\MediaWrite;
use Bjanczak\FilamentFlexFields\Support\Media\Images\Queue\QueuedImageConversions;
use Closure;
use LogicException;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Renditions\ExternalVariant;
use UseFlex\MediaStudio\Renditions\ExternalVariantProvider;

/** Optional reader for authenticated FLEX upload receipts. The owning application authorizes each receipt. */
final readonly class UploadManifestVariants implements ExternalVariantProvider
{
    /**
     * @param  Closure(object, object, object, Principal, MediaReceipt, string): bool  $authorize
     * @param  null|Closure(object, object, object, Principal, MediaReceipt, string): MediaWrite  $queueAuthority
     */
    public function __construct(private Closure $authorize, private ?Closure $queueAuthority = null) {}

    /** @return list<ExternalVariant> */
    public function variants(object $workspace, object $entry, object $version, Principal $principal): iterable
    {
        if (! class_exists(UploadManifestRepository::class)) {
            throw new LogicException('Install FLEX Fields to read its upload manifests.');
        }
        // A fresh repository avoids reusing its request-local manifest memoization in long-lived workers.
        $repository = new UploadManifestRepository(app('encrypter'));
        $manifest = $repository->read($version->disk, $version->object_key);
        if ($manifest === null) {
            return [];
        }
        $primary = $manifest->primaryReceipt();
        abort_unless($primary->adapter === 'disk' && $primary->identifier === $primary->path
            && $primary->disk === $version->disk && $primary->path === $version->object_key
            && is_string($version->sha256) && hash_equals($version->sha256, $primary->file->sha256)
            && (int) $version->size_bytes === $primary->file->bytes
            && $version->mime_type === $primary->file->mimeType
            && ($this->authorize)($workspace, $entry, $version, $principal, $primary, $manifest->primary) === true, 404);
        $receipts = $manifest->variants;
        if (array_key_exists('queued_images', $manifest->context)) {
            $journalId = $manifest->context['queued_images'];
            abort_unless(is_string($journalId) && $journalId !== '', 404);
            if ($this->queueAuthority === null) {
                throw new LogicException('Configure an owning-application MediaWrite authority to read queued FLEX conversions.');
            }
            $authority = ($this->queueAuthority)($workspace, $entry, $version, $principal, $manifest->original, $journalId);
            if (! $authority instanceof MediaWrite || $authority->adapter !== 'disk' || $authority->disk !== $manifest->original->disk) {
                throw new LogicException('Queued FLEX conversions require a disk MediaWrite authority for their original.');
            }
            $snapshot = app(QueuedImageConversions::class)->snapshot($journalId, $manifest->original, $authority);
            if ($snapshot['status'] !== 'ready') {
                return [];
            }
            $receipts = $snapshot['variants'];
        }
        abort_if(count($receipts) > 64, 422, 'Register at most 64 external variants at once.');
        $variants = [];
        foreach ($receipts as $name => $receipt) {
            if ($receipt->disk === $primary->disk && $receipt->path === $primary->path) {
                continue;
            }
            abort_unless($receipt->adapter === 'disk' && $receipt->identifier === $receipt->path
                && ($this->authorize)($workspace, $entry, $version, $principal, $receipt, $name) === true, 404);
            $variants[] = new ExternalVariant($name, $receipt->disk, $receipt->path, $receipt->file->mimeType,
                $receipt->file->bytes, $receipt->file->width, $receipt->file->height, $receipt->file->sha256);
        }

        return $variants;
    }
}
