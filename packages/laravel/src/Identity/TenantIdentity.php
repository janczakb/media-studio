<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Identity;

use InvalidArgumentException;
use LogicException;

final readonly class TenantIdentity
{
    public function __construct(public string $type, public string $id)
    {
        if (! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $type) || $id === '' || strlen($id) > 191 || ! mb_check_encoding($id, 'UTF-8')) {
            throw new InvalidArgumentException('Unsupported tenant identity.');
        }
    }

    public function key(): string
    {
        return hash('sha256', json_encode([$this->type, $this->id], JSON_THROW_ON_ERROR));
    }

    public static function fromWorkspace(object $workspace): ?self
    {
        if ($workspace->tenant_type === null && $workspace->tenant_id === null && $workspace->tenant_key === null) {
            return null;
        }
        if (! is_string($workspace->tenant_type) || ! is_string($workspace->tenant_id)) {
            throw new LogicException('Workspace tenant identity is incomplete.');
        }
        $tenant = new self($workspace->tenant_type, $workspace->tenant_id);
        if ($workspace->tenant_key !== $tenant->key()) {
            throw new LogicException('Workspace tenant identity is inconsistent.');
        }

        return $tenant;
    }
}
