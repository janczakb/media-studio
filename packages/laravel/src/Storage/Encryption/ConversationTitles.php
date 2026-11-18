<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Storage\Encryption;

use Illuminate\Support\Facades\DB;
use UseFlex\MediaCore\Encryption\EncryptionFailure;

/** Exact indexed prefix lookups, with at most 100 keyed tokens per conversation. */
final class ConversationTitles
{
    public function __construct(private DatabasePayloads $cipher) {}

    public function fields(string $id, int $workspace, string $principal, string $title): array
    {
        DB::table('fms_ai_title_tokens')->where('conversation_id', $id)->delete();
        if (! $this->cipher->enabled()) {
            return ['title' => $title, 'search_title' => mb_strtolower($title, 'UTF-8'), 'encrypted_title' => null];
        }
        $normalized = mb_strtolower($title, 'UTF-8');
        $tokens = [];
        for ($length = 1; $length <= min(100, mb_strlen($normalized, 'UTF-8')); $length++) {
            $tokens[] = ['conversation_id' => $id, 'token' => $this->cipher->indexToken($this->scope($workspace, $principal), mb_substr($normalized, 0, $length, 'UTF-8'))];
        }
        if ($tokens !== []) {
            DB::table('fms_ai_title_tokens')->insert($tokens);
        }

        return ['title' => '', 'search_title' => '', 'encrypted_title' => $this->cipher->seal('fms_ai_conversations', $id, 'encrypted_title', $title)];
    }

    public function read(object $row): string
    {
        if ($row->encrypted_title !== null) {
            return $this->cipher->open('fms_ai_conversations', $row->id, 'encrypted_title', $row->encrypted_title);
        }
        if ($this->cipher->enabled() && config('media-studio.encryption.database.allow_plaintext_reads') !== true) {
            throw new EncryptionFailure('Conversation title migration is incomplete.');
        }

        return $row->title;
    }

    public function searchTokens(int $workspace, string $principal, string $query): array
    {
        return array_map(fn ($id) => $this->cipher->indexToken($this->scope($workspace, $principal), mb_strtolower($query, 'UTF-8'), $id), $this->cipher->keys()->ids());
    }

    private function scope(int $workspace, string $principal): string
    {
        return json_encode(['ai-title-prefix-v1', $workspace, $principal], JSON_THROW_ON_ERROR);
    }
}
