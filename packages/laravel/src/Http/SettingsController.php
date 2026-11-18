<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UseFlex\MediaCore\Images\ImagePreset;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Cache\StudioCache;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\FileCategories;
use UseFlex\MediaStudio\Renditions\ImagePresets;
use UseFlex\MediaStudio\Renditions\VideoRecipes;
use UseFlex\MediaStudio\Uploads\AcceptedFiles;
use UseFlex\MediaStudio\Uploads\UploadSessions;

final class SettingsController
{
    public const MANAGE = 'studio.settings.manage';

    public function __construct(private WorkspaceAccess $access) {}

    public function show(Request $request, string $workspace): JsonResponse
    {
        $resolved = $this->access->resolve(Principal::fromUser($request->user()), $workspace);

        return response()->json($this->payload($request, $resolved));
    }

    public function update(Request $request, string $workspace): JsonResponse
    {
        $principal = Principal::fromUser($request->user());
        $resolved = DB::transaction(function () use ($request, $workspace, $principal): object {
            $resolved = $this->access->resolve($principal, $workspace, write: true, lock: true);
            abort_unless($this->canManage($request, $resolved), 403);
            $data = $request->validate([
                'revision' => ['required', 'integer', 'min:0'],
                'extensions' => ['present', 'array', 'list', 'max:512'],
                'extensions.*' => ['required', 'string', 'distinct', Rule::in(AcceptedFiles::configured())],
                'chunks' => ['required', 'boolean'],
                'chunkMaxMiB' => ['sometimes', 'nullable', 'integer', 'min:1', 'max:'.intdiv(app(UploadSessions::class)->policy()['maxBytes'], 1048576)],
            ]);
            abort_unless((int) $data['revision'] === (int) $resolved->studio_settings_revision, 409, 'Settings changed. Reopen settings and try again.');
            abort_if($data['chunks'] && ! config('media-studio.upload.sessions.enabled', false), 422, 'Chunk uploads are disabled by the server.');
            $stored = json_decode($resolved->studio_settings ?? '{}', true);
            $settings = ['extensions' => $data['extensions'], 'chunks' => (bool) $data['chunks']];
            $chunkMaxMiB = array_key_exists('chunkMaxMiB', $data) ? $data['chunkMaxMiB'] : ($stored['chunkMaxMiB'] ?? null);
            if ($chunkMaxMiB !== null) {
                $settings['chunkMaxMiB'] = (int) $chunkMaxMiB;
            }
            $resolved->studio_settings = json_encode($settings, JSON_THROW_ON_ERROR);
            $resolved->studio_settings_revision++;
            DB::table('fms_workspaces')->where('id', $resolved->id)->update([
                'studio_settings' => $resolved->studio_settings,
                'studio_settings_revision' => $resolved->studio_settings_revision,
            ]);

            return $resolved;
        });

        return response()->json($this->payload($request, $resolved));
    }

    private function canManage(Request $request, object $workspace): bool
    {
        if (! in_array($workspace->role, ['owner', 'admin', 'editor'], true)) {
            return false;
        }

        return app(StudioPermissions::class)->allows($request->user(), 'settings.manage', $workspace);
    }

    private function payload(Request $request, object $workspace): array
    {
        $available = AcceptedFiles::configured();
        $groups = app(StudioCache::class)->catalog(
            'settings-file-types', json_encode($available, JSON_THROW_ON_ERROR), function () use ($available): array {
                $groups = [];
                foreach (FileCategories::EXTENSIONS as $category => $extensions) {
                    $groups[$category] = array_values(array_intersect($extensions, $available));
                }
                $custom = array_values(array_diff($available, ...array_values($groups)));
                if ($custom !== []) {
                    $groups['other'] = $custom;
                }

                return $groups;
            },
        );

        $transfers = app(UploadSessions::class);
        $server = $transfers->policy();
        $upload = $transfers->policy($workspace);
        $stored = json_decode($workspace->studio_settings ?? '{}', true);
        $video = VideoRecipes::recipes();
        $poster = collect($video)->firstWhere('role', 'poster');

        return [
            'processing' => [
                'images' => array_values(array_map(static fn (ImagePreset $preset): array => [
                    'name' => $preset->name,
                    'stripMetadata' => $preset->recipe['strip_metadata'] ?? true,
                    ...array_intersect_key($preset->recipe, array_flip(['width', 'height', 'format', 'quality'])),
                ], ImagePresets::configured())),
                'video' => [
                    'compress' => collect($video)->contains('role', 'playback'),
                    'poster' => $poster === null ? null : substr($poster['mime'], strlen('image/')),
                ],
            ],
            'canManage' => $this->canManage($request, $workspace),
            'revision' => (int) ($workspace->studio_settings_revision ?? 0),
            'groups' => $groups,
            'extensions' => AcceptedFiles::extensions($workspace),
            'chunksAvailable' => $server['enabled'],
            'chunks' => $upload['enabled'],
            'chunkMaxMiB' => isset($stored['chunkMaxMiB']) ? min((int) $stored['chunkMaxMiB'], intdiv($server['maxBytes'], 1048576)) ?: null : null,
            'transferPolicy' => [
                'serverMaxMiB' => intdiv($server['maxBytes'], 1048576),
                'chunkBytes' => $upload['chunkBytes'],
                'maxParts' => $upload['maxParts'],
                'maxConcurrent' => $upload['maxConcurrent'],
                'multipartThresholdBytes' => $upload['multipartThresholdBytes'],
            ],
        ];
    }
}
