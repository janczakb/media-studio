<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Integrations\Filament\Actions;

use Filament\Forms\Components\Hidden;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Facades\Crypt;
use JsonException;
use UseFlex\MediaStudio\Identity\Principal;

final class ActionSnapshot
{
    public const FIELD = '_studioSnapshot';

    public static function field(): Hidden
    {
        return Hidden::make(self::FIELD)->required();
    }

    /** @param list<array{id:string,revision:int}> $entries */
    public static function issue(Principal $principal, string $workspace, string $action, array $entries): string
    {
        return Crypt::encryptString(json_encode([
            'principal' => $principal->key(), 'workspace' => $workspace, 'action' => $action,
            'entries' => $entries, 'expires' => now()->addMinutes(30)->getTimestamp(),
        ], JSON_THROW_ON_ERROR));
    }

    /**
     * @param  list<array{id:string,revision:int}>  $entries
     * @return list<array{id:string,revision:int}>
     */
    public static function verify(mixed $token, Principal $principal, string $workspace, string $action, array $entries): array
    {
        abort_unless(is_string($token) && strlen($token) <= 16384, 422, 'Invalid action confirmation.');
        try {
            $snapshot = json_decode(Crypt::decryptString($token), true, 6, JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            abort(422, 'Invalid action confirmation.');
        }
        abort_unless(is_array($snapshot) && ($snapshot['principal'] ?? null) === $principal->key()
            && ($snapshot['workspace'] ?? null) === $workspace && ($snapshot['action'] ?? null) === $action
            && ($snapshot['expires'] ?? 0) > now()->getTimestamp(), 409, 'Reopen the action to confirm the current selection.');
        $expected = array_column($snapshot['entries'], 'id');
        $current = array_column($entries, 'id');
        sort($expected);
        sort($current);
        abort_unless($expected === $current, 409, 'The selection changed. Reopen the action to confirm it.');

        return $snapshot['entries'];
    }
}
