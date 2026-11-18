<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class GetFile extends StudioReadTool
{
    protected string $name = 'studio_get_file';

    protected string $description = 'Read metadata for one authorized file or folder. File content is not downloaded.';

    protected function operation(): string
    {
        return 'file';
    }

    protected function rules(): array
    {
        return [
            'workspace' => ['required', 'string', 'ulid'],
            'entry' => ['required', 'string', 'ulid'],
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace ULID.')->required(),
            'entry' => $schema->string()->description('Entry ULID.')->required(),
        ];
    }
}
