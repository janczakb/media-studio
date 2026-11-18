<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Laravel\Mcp\Server;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Assistant\AssistantConfiguration;
use UseFlex\MediaStudio\Mcp\McpAccess;

final class AiController
{
    public function show(Request $request): JsonResponse
    {
        $installed = class_exists(Server::class);
        $enabled = config('media-studio.mcp.enabled') === true;
        $canUse = app(McpAccess::class)->allows($request->user());
        $tools = [];
        foreach (array_keys(McpAccess::TOOLS) as $tool) {
            if (app(McpAccess::class)->allows($request->user(), $tool)) {
                $tools[] = $tool;
            }
        }

        return response()->json([
            'assistant' => app(AssistantConfiguration::class)->publicConfiguration(),
            'mcp' => ['installed' => $installed, 'enabled' => $enabled, 'canUse' => $canUse,
                'endpoint' => $installed && $enabled && $canUse ? url((string) config('media-studio.mcp.path', 'media-studio/mcp')) : null,
                'authentication' => config('media-studio.mcp.authentication', 'bearer'), 'tools' => $tools],
            'api' => ['enabled' => config('media-studio.api.enabled') === true, 'canUse' => app(StudioPermissions::class)->allows($request->user(), 'api.use')],
            'search' => ['enabled' => config('media-studio.search.enabled') === true],
            'features' => [
                'imageTags' => ['implemented' => true, 'enabled' => config('media-studio.intelligence.enabled') === true && config('media-studio.intelligence.images') === true],
                'documentText' => ['implemented' => true, 'enabled' => config('media-studio.search.content.enabled') === true],
                'imageOcr' => ['implemented' => true, 'enabled' => config('media-studio.search.content.enabled') === true && config('media-studio.search.content.ocr.enabled') === true],
                'videoAnalysis' => ['implemented' => true, 'enabled' => config('media-studio.intelligence.enabled') === true && config('media-studio.intelligence.videos') === true],
                'semanticSearch' => ['implemented' => true, 'enabled' => config('media-studio.search.enabled') === true && config('media-studio.search.semantic.enabled') === true],
            ],
        ])->header('Cache-Control', 'private, no-store');
    }
}
