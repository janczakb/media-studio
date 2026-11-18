<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use Illuminate\Http\Request;
use UseFlex\MediaStudio\Identity\Principal;

final readonly class McpConnection
{
    private function __construct(
        public string $id,
        public Principal $principal,
        public ?string $tenantKey,
        public ?array $workspaces,
        public array $tools,
        public array $workspaceTools,
    ) {}

    public static function managed(): bool
    {
        return config('media-studio.mcp.authentication') === 'managed';
    }

    public static function fromRequest(Request $request): ?self
    {
        if (! self::managed() || ! preg_match('/\Afms_mcp_([a-z][a-z0-9_-]{0,63})\.([a-f0-9]{64})\z/', (string) $request->bearerToken(), $matches)) {
            return null;
        }

        [, $id, $secret] = $matches;
        $grant = ((array) config('media-studio.mcp.connections'))[$id] ?? null;
        if (! is_array($grant) || ! is_string($grant['token_hash'] ?? null)
            || ! hash_equals($grant['token_hash'], hash('sha256', $secret))
            || ! is_int($grant['expires_at'] ?? null) || $grant['expires_at'] <= time()
            || ($grant['enabled'] ?? true) !== true) {
            return null;
        }

        $type = $grant['principal_type'] ?? null;
        $identifier = $grant['principal_id'] ?? null;
        if (! is_string($type) || ! preg_match('/\A[a-z][a-z0-9_-]{0,63}\z/', $type)
            || ! is_string($identifier) || $identifier === '' || strlen($identifier) > 191
            || ! array_key_exists('tenant_key', $grant)
            || ($grant['tenant_key'] !== null && (! is_string($grant['tenant_key']) || ! preg_match('/\A[a-f0-9]{64}\z/', $grant['tenant_key'])))
            || ! array_key_exists('workspaces', $grant)
            || ($grant['workspaces'] !== null && ! is_array($grant['workspaces']))
            || ! is_array($grant['tools'] ?? null)) {
            return null;
        }

        $workspaceTools = $grant['workspace_tools'] ?? [];
        if (! is_array($workspaceTools)) {
            return null;
        }

        return new self($id, new Principal($type, $identifier), $grant['tenant_key'], $grant['workspaces'], $grant['tools'], $workspaceTools);
    }

    public function scope(): string
    {
        return hash('sha256', json_encode([$this->id, $this->principal->key(), $this->tenantKey], JSON_THROW_ON_ERROR));
    }
}
