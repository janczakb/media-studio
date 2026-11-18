<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Comments;

use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use UseFlex\MediaStudio\Identity\Principal;

/** Plain text and identity references only: no HTML, URLs, styles or executable nodes. */
final class CommentDocument
{
    public function normalize(mixed $runs, object $workspace, array $existing = []): array
    {
        abort_unless(is_array($runs) && array_is_list($runs), 422);
        abort_if(count($runs) > 200, 422);
        $document = [];
        $identities = [];
        $body = '';
        foreach ($runs as $run) {
            abort_unless(is_array($run), 422);
            if (($run['type'] ?? null) === 'text') {
                abort_unless(is_string($run['text'] ?? null), 422);
                $text = str_replace(["\r\n", "\r"], "\n", $run['text']);
                $document[] = ['type' => 'text', 'text' => $text];
                $body .= $text;
            } elseif (($run['type'] ?? null) === 'mention') {
                $type = $run['principalType'] ?? null;
                $id = $run['id'] ?? null;
                abort_unless(is_string($type) && array_key_exists($type, config('media-studio.principals', [])) && is_string($id) && $id !== '' && strlen($id) <= 191, 422);
                $p = new Principal($type, $id);
                $identities[$p->key()] = $p;
                $document[] = ['type' => 'mention', 'principalType' => $type, 'id' => $id];
                $body .= '@['.$p->key().']';
            } else {
                throw ValidationException::withMessages(['document' => 'Unsupported comment node.']);
            }
        }
        abort_if(count($identities) > 10 || mb_strlen($body) > 5000 || trim($body) === '', 422);
        $members = DB::table('fms_memberships')->where('workspace_id', $workspace->id)->whereIn('principal_key', array_keys($identities))->get()->keyBy('principal_key');
        $old = [];
        foreach ($existing as $run) {
            if (($run['type'] ?? null) === 'mention') {
                $old[(new Principal($run['principalType'], $run['id']))->key()] = true;
            }
        }
        foreach ($identities as $key => $p) {
            abort_unless(isset($members[$key]) || isset($old[$key]), 422, 'Mentioned person is no longer a team member.');
        }

        return ['document' => $document, 'body' => $body, 'mentions' => $members->map(fn ($m) => (string) $m->id)->values()->all()];
    }
}
