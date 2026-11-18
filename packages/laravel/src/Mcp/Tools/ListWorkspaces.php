<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
final class ListWorkspaces extends StudioReadTool
{
    protected string $name = 'studio_list_workspaces';

    protected string $description = 'List authorized workspaces. Returns at most 25 entries and an opaque continuation cursor.';

    protected function operation(): string
    {
        return 'workspaces';
    }

    protected function rules(): array
    {
        return [
            'cursor' => ['sometimes', 'string', 'max:4096'],
        ];
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'cursor' => $schema->string()->description('Continuation cursor from a previous response.'),
        ];
    }
}
