<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListFiles extends StudioReadTool
{
    protected string $name = 'studio_list_files';

    protected string $description = 'List an authorized workspace folder. Filename search matches prefixes. Returns at most 60 entries and an opaque cursor.';

    protected function operation(): string
    {
        return 'files';
    }

    protected function rules(): array
    {
        return [
            'workspace' => ['required', 'string', 'ulid'],
            'parent' => ['sometimes', 'string', 'ulid'],
            'search' => ['sometimes', 'string', 'max:100'],
            'cursor' => ['sometimes', 'string', 'max:4096'],
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace ULID.')->required(),
            'parent' => $schema->string()->description('Folder ULID. Omit for the root.'),
            'search' => $schema->string()->description('Filename prefix, at most 100 characters.'),
            'cursor' => $schema->string()->description('Opaque continuation cursor.'),
        ];
    }
}
