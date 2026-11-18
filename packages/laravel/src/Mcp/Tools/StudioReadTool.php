<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Mcp\Tools;

use Illuminate\Validation\ValidationException;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UseFlex\MediaStudio\Mcp\McpAccess;
use UseFlex\MediaStudio\Mcp\StudioReader;

abstract class StudioReadTool extends Tool
{
    abstract protected function rules(): array;

    abstract protected function operation(): string;

    public function shouldRegister(): bool
    {
        return app(McpAccess::class)->allows(request()->user(), $this->name());
    }

    final public function handle(Request $request): Response
    {
        if (! app(McpAccess::class)->allows(request()->user(), $this->name())) {
            return Response::error('Access denied.');
        }
        try {
            $arguments = $request->validate($this->rules());

            return Response::json(app(StudioReader::class)->{$this->operation()}(request()->user(), $arguments));
        } catch (ValidationException) {
            return Response::error('Invalid arguments. Check the tool schema and opaque cursor.');
        } catch (HttpExceptionInterface) {
            return Response::error('The requested data is unavailable or access was denied.');
        } catch (Throwable $error) {
            report($error);

            return Response::error('The request could not be completed. Try again later.');
        }
    }
}
