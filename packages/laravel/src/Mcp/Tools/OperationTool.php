<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UseFlex\MediaStudio\Mcp\McpAccess;
use UseFlex\MediaStudio\Mcp\McpConnection;
use UseFlex\MediaStudio\Mcp\OperationCatalog;
use UseFlex\MediaStudio\Mcp\StudioOperations;

final class OperationTool extends Tool
{
    public function __construct(string $name, private readonly array $definition)
    {
        $this->name = $name;
        $this->description = $definition['description'];
    }

    public function shouldRegister(): bool
    {
        return app(McpAccess::class)->allows(request()->user(), $this->name);
    }

    public function annotations(): array
    {
        return ['readOnlyHint' => $this->definition['readOnly'] ?? false,
            'destructiveHint' => $this->definition['destructive'] ?? false,
            'idempotentHint' => $this->definition['idempotent'] ?? false,
            'openWorldHint' => $this->definition['openWorld'] ?? false];
    }

    public function schema(JsonSchema $schema): array
    {
        $result = [];
        foreach ($this->fields() as $name => $field) {
            $rules = explode('|', $field['rules']);
            $type = match (true) {
                in_array('integer', $rules, true) => $schema->integer(),
                in_array('boolean', $rules, true) => $schema->boolean(),
                in_array('array', $rules, true) => $schema->array()->items($name === 'items'
                    ? $schema->object(['id' => $schema->string()->required(), 'revision' => $schema->integer()->min(1)->required()])
                    : $schema->string()),
                default => $schema->string(),
            };
            foreach ($rules as $rule) {
                [$constraint, $value] = array_pad(explode(':', $rule, 2), 2, null);
                match ($constraint) {
                    'required', 'present' => $type->required(),
                    'nullable' => $type->nullable(),
                    'min' => $type->min((int) $value),
                    'max' => $type->max((int) $value),
                    'size' => $type->min((int) $value)->max((int) $value),
                    'in' => $type->enum(explode(',', $value)),
                    default => null,
                };
            }
            $result[$name] = $type->description($field['description']);
        }

        return $result;
    }

    public function toArray(): array
    {
        $tool = parent::toArray();
        $tool['inputSchema']['additionalProperties'] = false;

        return $tool;
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (! $this->shouldRegister()) {
            return Response::error('access_denied: This operation is unavailable.');
        }

        $rules = array_map(static fn (array $field): string => $field['rules'], $this->fields());
        try {
            abort_if(array_diff_key($request->all(), $rules) !== [], 422);
            $rules = array_map(static fn (string $value): array => explode('|', $value), $rules);
            foreach (['key' => 'regex:/\A[a-zA-Z0-9_-]+\z/', 'sha256' => 'regex:/\A[a-f0-9]{64}\z/', 'etag' => 'regex:/\A"[\x21\x23-\x7e]{1,126}"\z/'] as $field => $rule) {
                if (isset($rules[$field])) {
                    $rules[$field][] = $rule;
                }
            }
            if (isset($rules['mentions'])) {
                $rules['mentions.*'] = ['string', 'regex:/\A[0-9]{1,20}\z/', 'distinct'];
            }
            if (isset($rules['items'])) {
                $rules['items.*'] = ['required', 'array:id,revision'];
                $rules['items.*.id'] = ['required', 'ulid', 'distinct:ignore_case'];
                $rules['items.*.revision'] = ['required', 'integer', 'min:1'];
            }
            if (isset($rules['tags'])) {
                $rules['tags.*'] = ['required', 'string', 'max:120'];
            }
            $arguments = $request->validate($rules);
            foreach (['workspace', 'entry', 'parent', 'comment', 'session', 'version'] as $id) {
                if (isset($arguments[$id])) {
                    $arguments[$id] = strtoupper($arguments[$id]);
                }
            }
            if (isset($arguments['revision'])) {
                $arguments['revision'] = (int) $arguments['revision'];
            }
            $connection = McpConnection::fromRequest(request());
            abort_if($connection === null, 403);
            $result = app(StudioOperations::class)->execute($connection, $this->name, $arguments);
            $this->audit('ok', $connection, $arguments['workspace'] ?? null);

            return Response::structured($result);
        } catch (ValidationException) {
            return Response::error('invalid_arguments: Check the tool schema and supplied values.');
        } catch (HttpExceptionInterface $error) {
            $this->audit((string) $error->getStatusCode(), $connection ?? null, $arguments['workspace'] ?? null);

            return Response::error(match ($error->getStatusCode()) {
                401, 403, 404 => 'access_denied: The requested operation or resource is unavailable.',
                409 => 'conflict: Refresh the resource or upload state before retrying.',
                410 => 'expired: The result is no longer available.',
                422 => 'invalid_arguments: Check the tool schema and current workspace policy.',
                429 => 'rate_limited: Retry with backoff.',
                default => 'operation_failed: The operation could not be completed.',
            });
        } catch (Throwable $error) {
            Log::error('Media Studio MCP operation failed', ['tool' => $this->name, 'exception_type' => $error::class]);

            return Response::error('operation_failed: The operation could not be completed.');
        }
    }

    private function fields(): array
    {
        $fields = [];
        foreach ($this->definition['fields'] as $key) {
            $field = OperationCatalog::FIELDS[$key];
            $fields[$field['name'] ?? $key] = $field;
        }

        return $fields;
    }

    private function audit(string $outcome, ?McpConnection $connection, ?string $workspace): void
    {
        if ($connection !== null && ! ($this->definition['readOnly'] ?? false)) {
            Log::info('Media Studio MCP operation', ['tool' => $this->name, 'connection' => $connection->id,
                'principal' => $connection->principal->key(), 'workspace' => $workspace, 'outcome' => $outcome]);
        }
    }
}
