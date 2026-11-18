<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;

final class AssistantProfileController
{
    private function scope(Request $request, string $workspace): array
    {
        abort_unless(app(AssistantConfiguration::class)->available(), 404);
        $principal = Principal::fromUser($request->user());
        $scope = app(WorkspaceAccess::class)->resolve($principal, $workspace);

        return ['workspace_id' => $scope->id, 'principal_key' => $principal->key()];
    }

    public function show(Request $request, string $workspace)
    {
        $row = DB::table('fms_ai_profiles')->where($this->scope($request, $workspace))->first();

        return response()->json($row ? ['revision' => (int) $row->revision, 'name' => $row->name, 'character' => json_decode($row->character, true, flags: JSON_THROW_ON_ERROR)] : ['revision' => 0, 'name' => 'Assistant', 'character' => null])->header('Cache-Control', 'private, no-store');
    }

    public function update(Request $request, string $workspace)
    {
        $scope = $this->scope($request, $workspace);
        $rules = [
            'revision' => ['required', 'integer', 'min:0'],
            'name' => ['required', 'string', 'max:60', 'regex:/\A[^\p{C}]+\z/u'],
            'character' => ['required', 'array:version,personality,intensity,trackPointer,shape,color,ink,eyes,mouth,brows,accessory,hair,hairColor,cheeks,eyeSpacing,eyeSize,faceY'],
            'character.version' => ['required', 'integer', 'in:1'],
        ];
        foreach ([
            'personality' => ['calm', 'curious', 'energetic'],
            'shape' => ['pebble', 'bloom', 'squircle', 'diamond', 'star', 'cloud', 'capsule', 'triangle', 'hexagon', 'pentagon', 'wide'],
            'eyes' => ['oval', 'round', 'smile', 'sleepy', 'spark', 'wink', 'curious', 'focused', 'surprised', 'calm', 'skeptical', 'worried', 'excited', 'shy', 'angry', 'sad', 'confused', 'thinking'],
            'mouth' => ['smile', 'soft', 'open', 'grin', 'neutral'], 'brows' => ['none', 'soft', 'curious', 'focused'],
            'accessory' => ['none', 'glasses', 'freckles', 'halo', 'antenna', 'leaf'],
            'hair' => ['none', 'tuft', 'swept', 'curls', 'mohawk', 'orbit'],
        ] as $key => $values) {
            $rules['character.'.$key] = ['required', Rule::in($values)];
        }
        foreach (['color', 'ink', 'hairColor'] as $key) {
            $rules['character.'.$key] = ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'];
        }
        foreach (['trackPointer', 'cheeks'] as $key) {
            $rules['character.'.$key] = ['required', 'boolean'];
        }
        foreach (['intensity' => [0, 1.5], 'eyeSpacing' => [18, 36], 'eyeSize' => [.7, 1.3], 'faceY' => [-12, 12]] as $key => [$min, $max]) {
            $rules['character.'.$key] = ['required', 'numeric', "between:$min,$max"];
        }
        $data = $request->validate($rules);
        abort_if(trim($data['name']) === '', 422);

        return DB::transaction(function () use ($scope, $data) {
            // The workspace row also serializes first creation, when no profile row exists yet.
            DB::table('fms_workspaces')->where('id', $scope['workspace_id'])->lockForUpdate()->first();
            $previous = DB::table('fms_ai_profiles')->where($scope)->lockForUpdate()->first();
            abort_unless(($previous ? (int) $previous->revision : 0) === $data['revision'], 409, 'The appearance changed in another session. Reopen Customize before saving.');
            $value = ['name' => trim($data['name']), 'revision' => $data['revision'] + 1, 'character' => json_encode($data['character'], JSON_THROW_ON_ERROR), 'updated_at' => now()];
            if ($previous) {
                DB::table('fms_ai_profiles')->where($scope)->update($value);
            } else {
                DB::table('fms_ai_profiles')->insert([...$scope, ...$value, 'created_at' => now()]);
            }

            return response()->json(['name' => $value['name'], 'revision' => $value['revision'], 'character' => $data['character']])->header('Cache-Control', 'private, no-store');
        });
    }
}
