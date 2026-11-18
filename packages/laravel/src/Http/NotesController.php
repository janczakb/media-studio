<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Http;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Library\Entries;
use UseFlex\MediaStudio\Navigation\NavigationChanges;
use UseFlex\MediaStudio\Notes\NoteDocument;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class NotesController
{
    public function __construct(private WorkspaceAccess $access, private Entries $entries, private NoteDocument $documents) {}

    public function show(Request $request, string $workspace, string $entry): JsonResponse
    {
        $w = $this->access->resolve(Principal::fromUser($request->user()), $workspace);
        $e = $this->entries->resolve($w->id, $entry);
        abort_if($e->trashed_at !== null || $e->media_type !== 'note', 404);

        return response()->json($this->payload($e));
    }

    public function store(Request $request, string $workspace): JsonResponse
    {
        $data = $this->validate($request);
        $principal = Principal::fromUser($request->user());
        $key = hash('sha256', $workspace.$principal->key().$data['key']);
        $json = $this->documents->encode($data['document']);
        $data['document'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $fingerprint = hash('sha256', json_encode([$data['title'], $data['background'], $json, $data['parent'] ?? null], JSON_THROW_ON_ERROR));
        $entry = DB::transaction(function () use ($workspace, $principal, $data, $json, $key, $fingerprint): object {
            $w = $this->access->resolve($principal, $workspace, write: true, lock: true);
            $existing = DB::table('fms_notes')->where('request_key', $key)->first();
            if ($existing) {
                abort_unless(hash_equals($existing->fingerprint, $fingerprint), 409);

                return DB::table('fms_entries')->where('workspace_id', $w->id)->where('id', $existing->entry_id)->firstOrFail();
            }
            $parent = $this->entries->parentId($w->id, $data['parent'] ?? null, write: true);
            $title = trim($data['title']);
            abort_if($title === '', 422);
            $id = DB::table('fms_entries')->insertGetId(['public_id' => (string) Str::ulid(), 'workspace_id' => $w->id, 'parent_id' => $parent, 'parent_scope' => $parent ?? 0, 'kind' => 'file', 'sort_group' => 1, 'name' => $title, 'normalized_name' => $this->entries->normalizeName($title), 'media_type' => 'note', 'mime_type' => 'application/vnd.flex.note+json', 'note_excerpt' => null, 'note_background' => $data['background'], 'size_bytes' => strlen($json), 'created_at' => now(), 'updated_at' => now()]);
            DB::table('fms_entries')->where('id', $id)->update(['note_excerpt' => app(DatabasePayloads::class)->seal('fms_entries', $id, 'note_excerpt', $this->documents->excerpt($data['document']))]);
            DB::table('fms_notes')->insert(['entry_id' => $id, 'request_key' => $key, 'fingerprint' => $fingerprint, 'background' => $data['background'], 'document' => app(DatabasePayloads::class)->seal('fms_notes', $id, 'document', $json)]);

            $entry = DB::table('fms_entries')->where('id', $id)->first();
            app(NavigationChanges::class)->entries((int) $w->id, 'created', [$entry]);

            return $entry;
        }, 3);

        return response()->json($this->payload($entry), 201);
    }

    public function update(Request $request, string $workspace, string $entry): JsonResponse
    {
        $data = $this->validate($request, true);
        $json = $this->documents->encode($data['document']);
        $data['document'] = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        $e = DB::transaction(function () use ($request, $workspace, $entry, $data, $json): object {
            $w = $this->access->resolve(Principal::fromUser($request->user()), $workspace, write: true, lock: true);
            $e = $this->entries->resolve($w->id, $entry);
            abort_if($e->media_type !== 'note' || $e->trashed_at !== null, 404);
            abort_unless((int) $e->revision === $data['revision'], 409, 'The note changed. Reopen it before saving.');
            $title = trim($data['title']);
            abort_if($title === '', 422);
            DB::table('fms_entries')->where('id', $e->id)->update(['name' => $title, 'normalized_name' => $this->entries->normalizeName($title), 'note_excerpt' => app(DatabasePayloads::class)->seal('fms_entries', $e->id, 'note_excerpt', $this->documents->excerpt($data['document'])), 'note_background' => $data['background'], 'size_bytes' => strlen($json), 'revision' => $e->revision + 1, 'updated_at' => now()]);
            DB::table('fms_notes')->where('entry_id', $e->id)->update(['background' => $data['background'], 'document' => app(DatabasePayloads::class)->seal('fms_notes', $e->id, 'document', $json)]);

            $fresh = DB::table('fms_entries')->where('id', $e->id)->first();
            app(NavigationChanges::class)->entries((int) $w->id, 'update', [$fresh], [(int) $e->id => $e->parent_id]);

            return $fresh;
        }, 3);

        return response()->json($this->payload($e));
    }

    private function validate(Request $request, bool $update = false): array
    {
        return $request->validate(['title' => ['required', 'string', 'max:191'], 'background' => ['required', 'regex:/\A#[0-9a-fA-F]{6}\z/'], 'document' => ['required', 'array'], 'parent' => ['nullable', 'ulid'], 'key' => [$update ? 'sometimes' : 'required', 'string', 'min:16', 'max:100'], 'revision' => [$update ? 'required' : 'sometimes', 'integer', 'min:1']]);
    }

    private function payload(object $entry): array
    {
        $note = DB::table('fms_notes')->where('entry_id', $entry->id)->first();
        abort_if($note === null, 404);

        return ['entry' => $this->entries->card($entry), 'document' => json_decode(app(DatabasePayloads::class)->open('fms_notes', $entry->id, 'document', $note->document), true, 512, JSON_THROW_ON_ERROR), 'background' => $note->background];
    }
}
