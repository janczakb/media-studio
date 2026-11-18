<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class SearchFiles extends StudioReadTool
{
    protected string $name = 'studio_search_files';

    protected string $description = 'Search the configured lexical index. Results reflect indexed data, current permissions and current file versions.';

    protected function operation(): string
    {
        return 'search';
    }

    protected function rules(): array
    {
        return [
            'workspace' => ['required', 'string', 'ulid'],
            'q' => ['required', 'string', 'max:200'],
            'cursor' => ['sometimes', 'string', 'max:4096'],
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'workspace' => $schema->string()->description('Workspace ULID.')->required(),
            'q' => $schema->string()->description('Search phrase, at most 200 characters.')->required(),
            'cursor' => $schema->string()->description('Opaque continuation cursor.'),
        ];
    }
}
