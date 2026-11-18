<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Activity;

use Illuminate\Pagination\Cursor;
use Illuminate\Support\Facades\Crypt;
use Throwable;

final readonly class ScopedCursor
{
    public function __construct(private string $scope) {}

    public function decode(?string $value): ?Cursor
    {
        if ($value === null) {
            return null;
        }
        try {
            $payload = json_decode(Crypt::decryptString($value), true, 8, JSON_THROW_ON_ERROR);
            abort_unless(($payload['scope'] ?? null) === $this->scope && ($payload['expires'] ?? 0) > time(), 422);
            $cursor = Cursor::fromEncoded($payload['cursor'] ?? null);
            abort_if($cursor === null, 422);

            return $cursor;
        } catch (Throwable) {
            abort(422, 'This cursor is invalid or belongs to another view.');
        }
    }

    public function encode(?Cursor $cursor): ?string
    {
        return $cursor === null ? null : Crypt::encryptString(json_encode([
            'scope' => $this->scope, 'cursor' => $cursor->encode(), 'expires' => time() + 3600,
        ], JSON_THROW_ON_ERROR));
    }
}
