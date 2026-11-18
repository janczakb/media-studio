<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Identity;

use Illuminate\Contracts\Auth\Authenticatable;

final readonly class Principal
{
    public function __construct(public string $type, public string $id) {}

    public function key(): string
    {
        return hash('sha256', json_encode([$this->type, $this->id], JSON_THROW_ON_ERROR));
    }

    public static function fromUser(?Authenticatable $user): self
    {
        abort_if($user === null, 401);
        $type = array_search($user::class, config('media-studio.principals', []), true);
        abort_unless(is_string($type) && preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $type), 403, 'Principal model is not registered.');
        $id = (string) $user->getAuthIdentifier();
        abort_if($id === '' || strlen($id) > 191, 403, 'Unsupported principal identifier.');

        return new self($type, $id);
    }
}
