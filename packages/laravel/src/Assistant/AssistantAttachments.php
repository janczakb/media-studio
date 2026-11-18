<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use UseFlex\MediaCore\Encryption\AesGcmHkdfStream;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Storage\Encryption\DatabasePayloads;

final class AssistantAttachments
{
    public function directory(): string
    {
        return (string) config('media-studio.assistant.attachment_directory', storage_path('app/private/media-studio-assistant'));
    }

    public function path(string $id): string
    {
        abort_unless(preg_match('/\A[0-9A-HJKMNP-TV-Z]{26}\z/', $id), 422);

        return $this->directory().'/'.$id;
    }

    public function stage(object $scope, Principal $principal, UploadedFile $file): array
    {
        $mime = $file->getMimeType();
        $bytes = $file->getSize();
        abort_unless(in_array($mime, ['application/pdf', 'image/jpeg', 'image/png', 'image/webp', 'text/plain', 'text/csv', 'application/json'], true), 422, 'Upload a PDF, image, TXT, Markdown, CSV or JSON file.');
        abort_if($file->getSize() > 8 * 1024 * 1024, 422, 'Attachments are limited to 8 MB each.');
        $id = (string) Str::ulid();
        $name = Str::limit(basename(str_replace('\\', '/', $file->getClientOriginalName())), 180);
        abort_if(preg_match('/[\p{C}]/u', $name), 422, 'Unsupported filename.');
        DB::transaction(function () use ($scope, $principal, $file, $id, $name, $mime): void {
            DB::table('fms_workspaces')->where('id', $scope->id)->lockForUpdate()->first();
            $pending = DB::table('fms_ai_attachments')->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->whereNull('run_id')->where('expires_at', '>', now());
            abort_if((clone $pending)->count() >= 10 || (clone $pending)->sum('bytes') + $file->getSize() > 24 * 1024 * 1024, 429, 'Too many pending attachments. Send your current files first.');
            DB::table('fms_ai_attachments')->insert(['id' => $id, 'workspace_id' => $scope->id, 'principal_key' => $principal->key(), 'state' => 'staging', 'name' => $name, 'mime' => $mime, 'bytes' => $file->getSize(), 'expires_at' => now()->addDay(), 'created_at' => now(), 'updated_at' => now()]);
        });
        $directory = $this->directory();
        try {
            if (! is_dir($directory) && ! mkdir($directory, 0700, true) && ! is_dir($directory)) {
                throw new \RuntimeException('Attachment directory is unavailable.');
            }
            if (app(DatabasePayloads::class)->enabled()) {
                $input = fopen($file->getPathname(), 'rb');
                $output = fopen($this->path($id), 'xb');
                if (! is_resource($input) || ! is_resource($output)) {
                    if (is_resource($input)) {
                        fclose($input);
                    }
                    if (is_resource($output)) {
                        fclose($output);
                    }
                    throw new \RuntimeException('Attachment streams are unavailable.');
                }
                chmod($this->path($id), 0600);
                try {
                    $keyId = app(DatabasePayloads::class)->keys()->active;
                    if (fwrite($output, 'FMSATT01'.str_pad($keyId, 32, "\0")) !== 40) {
                        throw new \RuntimeException('Attachment key header failed.');
                    }
                    $this->attachmentCipher($keyId)->encrypt($input, $output, $this->attachmentContext($id));
                } finally {
                    fclose($input);
                    fclose($output);
                }
            } else {
                $file->move($directory, $id);
            }
            chmod($this->path($id), 0600);
            DB::table('fms_ai_attachments')->where('id', $id)->update(['state' => 'ready', 'updated_at' => now()]);
        } catch (\Throwable $e) {
            // Keep the durable staging receipt for bounded retryable cleanup.
            DB::table('fms_ai_attachments')->where('id', $id)->update(['expires_at' => now()]);
            throw $e;
        }

        return ['id' => $id, 'name' => $name, 'size' => $bytes, 'mimeType' => $mime];
    }

    public function claim(array $ids, object $scope, Principal $principal, string $run, string $conversation): void
    {
        $rows = DB::table('fms_ai_attachments')->whereIn('id', $ids)->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->where('state', 'ready')->whereNull('run_id')->where('expires_at', '>', now())->lockForUpdate()->get();
        abort_unless($rows->count() === count($ids), 404, 'An attachment is no longer available.');
        abort_if($rows->sum('bytes') > 16 * 1024 * 1024, 422, 'Use at most 16 MB of attachments per message.');
        DB::table('fms_ai_attachments')->whereIn('id', $ids)->update(['run_id' => $run, 'conversation_id' => $conversation, 'updated_at' => now()]);
    }

    public function forProvider(object $scope, Principal $principal, string $run): array
    {
        $rows = DB::table('fms_ai_attachments')->where('run_id', $run)->where('workspace_id', $scope->id)->where('principal_key', $principal->key())->limit(5)->get();
        $items = [];
        foreach ($rows as $row) {
            abort_unless($row->state === 'ready' && strtotime($row->expires_at) > time() && is_file($this->path($row->id)), 410, 'An attachment expired. Upload it again.');
            $path = $this->path($row->id);
            $probe = fopen($path, 'rb');
            if (! is_resource($probe)) {
                throw new \RuntimeException('Attachment is unavailable.');
            }
            try {
                $encrypted = fread($probe, 8) === 'FMSATT01';
            } finally {
                fclose($probe);
            }
            if ($encrypted || app(DatabasePayloads::class)->enabled()) {
                if (! $encrypted) {
                    throw new \RuntimeException('Re-upload this legacy attachment before using encrypted mode.');
                }
                $input = fopen($path, 'rb');
                if (! is_resource($input)) {
                    throw new \RuntimeException('Attachment is unavailable.');
                }
                try {
                    fseek($input, 8);
                    $keyId = rtrim(fread($input, 32), "\0");
                    $reader = $this->attachmentCipher($keyId)->reader(function ($start, $length) use ($input) {
                        if (fseek($input, $start + 40) !== 0) {
                            throw new \RuntimeException('Attachment seek failed.');
                        }

                        return fread($input, $length);
                    }, filesize($path) - 40, $this->attachmentContext($row->id));
                    $reader->verifyEnd();
                    if ($reader->size !== (int) $row->bytes) {
                        throw new \RuntimeException('Attachment size mismatch.');
                    }
                    $content = '';
                    for ($offset = 0; $offset < $reader->size; $offset += 65536) {
                        $content .= $reader->read($offset, 65536);
                    }
                    $items[] = ['content' => $content, 'mime' => $row->mime, 'name' => $row->name];
                } finally {
                    fclose($input);
                }
            } else {
                $items[] = ['path' => $this->path($row->id), 'mime' => $row->mime, 'name' => $row->name];
            }
        }

        return $items;
    }

    public function prune(): void
    {
        $rows = DB::table('fms_ai_attachments as a')->whereIn('a.state', ['ready', 'staging'])->where('a.expires_at', '<', now()->subMinutes(10))->whereNotExists(fn ($q) => $q->selectRaw('1')->from('fms_ai_runs as r')->whereColumn('r.id', 'a.run_id')->whereIn('r.state', ['queued', 'running']))->orderBy('a.expires_at')->limit(100)->get(['a.id']);
        foreach ($rows as $row) {
            $path = $this->path($row->id);
            if (is_file($path) && ! unlink($path)) {
                continue;
            }
            DB::table('fms_ai_attachments')->where('id', $row->id)->update(['state' => 'expired']);
        }
    }

    private function attachmentCipher(string $keyId): AesGcmHkdfStream
    {
        $keys = app(DatabasePayloads::class)->keys();

        return new AesGcmHkdfStream(hash_hkdf('sha256', $keys->get($keyId), 32, 'fms-assistant-attachment-v1'));
    }

    private function attachmentContext(string $id): string
    {
        return 'fms-ai-attachment-v1:'.$id;
    }
}
