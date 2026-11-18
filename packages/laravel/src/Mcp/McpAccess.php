<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp;

use Illuminate\Contracts\Auth\Authenticatable;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Identity\Principal;

final class McpAccess
{
    public const TOOLS = [
        'studio_list_workspaces' => Tools\ListWorkspaces::class,
        'studio_list_files' => Tools\ListFiles::class,
        'studio_get_file' => Tools\GetFile::class,
        'studio_search_files' => Tools\SearchFiles::class,
    ];

    public function allows(?Authenticatable $actor, ?string $tool = null): bool
    {
        if ($actor === null || config('media-studio.mcp.enabled') !== true || ! app(StudioPermissions::class)->allows($actor, 'mcp.use')) {
            return false;
        }

        if ($tool === null) {
            return true;
        }

        $builtin = isset(self::TOOLS[$tool]);
        $operation = OperationCatalog::TOOLS[$tool] ?? null;
        if ((! $builtin && $operation === null)
            || ! in_array($tool, (array) (config('media-studio.mcp.tools') ?? [...array_keys(self::TOOLS), ...array_keys(OperationCatalog::TOOLS)]), true)
            || ($tool === 'studio_search_files' && config('media-studio.search.enabled') !== true)
            || (isset($operation['feature']) && config($operation['feature'], $operation['featureDefault'] ?? false) !== true)) {
            return false;
        }

        if (! McpConnection::managed()) {
            return $builtin;
        }

        $client = McpConnection::fromRequest(request());

        return $client !== null && $client->principal->key() === Principal::fromUser($actor)->key()
            && in_array($tool, $client->tools, true);
    }

    public function workspaces(): ?array
    {
        $global = config('media-studio.mcp.workspaces');
        $global = $global === null ? null : (is_array($global) ? $global : []);
        $grant = McpConnection::fromRequest(request())?->workspaces;

        return $global === null ? $grant : ($grant === null ? $global : array_values(array_intersect($global, $grant)));
    }

    public function workspace(string $workspace, string $tool): void
    {
        $allowed = $this->workspaces();
        abort_unless($allowed === null || in_array($workspace, $allowed, true), 404);
        $client = McpConnection::fromRequest(request());
        if ($client !== null && array_key_exists($workspace, $client->workspaceTools)) {
            $tools = $client->workspaceTools[$workspace];
            abort_unless(is_array($tools) && in_array($tool, $tools, true), 404);
        }
    }
}
