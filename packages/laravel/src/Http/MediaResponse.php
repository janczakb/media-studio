<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Filesystem\AwsS3V3Adapter;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\Response;
use UseFlex\MediaStudio\Delivery\Delivery;
use UseFlex\MediaStudio\Delivery\DeliveryResource;

final class MediaResponse
{
    public function stream(Request $request, object $record, ?DeliveryResource $resource = null): Response
    {
        if ($resource !== null && ($redirect = app(Delivery::class)->redirect($resource)) !== null) {
            return $redirect;
        }
        $size = $record->size_bytes === null ? Storage::disk($record->disk)->size($record->object_key) : (int) $record->size_bytes;
        $start = 0;
        $end = max(0, $size - 1);
        $partial = false;
        $range = $request->header('Range');
        if (is_string($range)) {
            abort_unless(preg_match('/\Abytes=(\d*)-(\d*)\z/', $range, $parts) && $size > 0, 416, 'Unsupported range.', ['Content-Range' => 'bytes */'.$size]);
            abort_if($parts[1] === '' && $parts[2] === '', 416, 'Invalid range.', ['Content-Range' => 'bytes */'.$size]);
            if ($parts[1] === '') {
                $start = max(0, $size - (int) $parts[2]);
                abort_if((int) $parts[2] === 0, 416, 'Invalid suffix.', ['Content-Range' => 'bytes */'.$size]);
            } else {
                $start = (int) $parts[1];
                $end = $parts[2] === '' ? $end : min($end, (int) $parts[2]);
            }
            abort_if($start >= $size || $start > $end, 416, 'Range outside the object.', ['Content-Range' => 'bytes */'.$size]);
            $partial = true;
        }
        $length = $size === 0 ? 0 : $end - $start + 1;
        $disk = Storage::disk($record->disk);
        if ($disk instanceof AwsS3V3Adapter) {
            // S3 serves precisely the requested bytes; no complete download/seek fallback.
            $result = $disk->getClient()->getObject([
                'Bucket' => $disk->getConfig()['bucket'], 'Key' => $disk->path($record->object_key),
                ...($partial ? ['Range' => 'bytes='.$start.'-'.$end] : []),
                '@http' => ['stream' => true],
            ]);
            $body = $result['Body'];
            $reader = static fn (int $bytes): string => $body->read($bytes);
            $close = static fn () => $body->close();
        } else {
            $stream = $disk->readStream($record->object_key);
            abort_unless(is_resource($stream), 404);
            if ($start > 0 && fseek($stream, $start) !== 0) {
                fclose($stream);
                abort(416, 'This storage does not support seeking.');
            }
            $reader = static function (int $bytes) use ($stream): string {
                $chunk = fread($stream, $bytes);
                if ($chunk === false) {
                    throw new \RuntimeException('The media stream could not be read.');
                }

                return $chunk;
            };
            $close = static fn () => fclose($stream);
        }
        $inline = preg_match('~\A(?:image/(?:jpeg|png|webp|avif|gif)|video/[a-z0-9.+-]+|audio/[a-z0-9.+-]+|application/pdf)\z~', $record->mime_type) === 1 && ! ($resource?->download || $request->boolean('download'));
        $response = response()->streamDownload(static function () use ($reader, $close, $length): void {
            try {
                $remaining = $length;
                while ($remaining > 0 && ! connection_aborted()) {
                    $chunk = $reader(min(65536, $remaining));
                    if ($chunk === '') {
                        break;
                    }
                    echo $chunk;
                    $remaining -= strlen($chunk);
                }
            } finally {
                $close();
            }
        }, $record->name, ['Content-Type' => $record->mime_type, 'Content-Length' => (string) $length, 'Accept-Ranges' => 'bytes', 'Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff'], $inline ? 'inline' : 'attachment');
        if ($partial) {
            $response->setStatusCode(206);
            $response->headers->set('Content-Range', 'bytes '.$start.'-'.$end.'/'.$size);
        }

        return $response;
    }
}
