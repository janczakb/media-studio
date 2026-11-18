<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Assistant;

use Closure;
use Illuminate\Support\Facades\DB;
use UseFlex\MediaStudio\Access\SourcePolicy;
use UseFlex\MediaStudio\Access\StudioPermissions;
use UseFlex\MediaStudio\Access\TrustedClientContext;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;
use UseFlex\MediaStudio\Library\Entries;

final readonly class AssistantToolContext
{
    public function __construct(public string $run, public Principal $principal, public string $workspace, public ?TenantIdentity $tenant) {}

    public function execute(string $operation, Closure $callback, bool $active = true): mixed
    {
        $scope = app(WorkspaceAccess::class)->resolveForWorker($this->principal, $this->workspace, $this->tenant, operation: 'ai.view');
        $actor = app(StudioPermissions::class)->actor($this->principal);
        app(StudioPermissions::class)->authorize($actor, $operation, $scope);
        if ($active) {
            abort_unless(DB::table('fms_ai_runs')->where('id', $this->run)->where('state', 'running')->exists(), 409, 'The response has stopped.');
        }
        $request = request();
        $resolver = $request->getUserResolver();
        $prior = [TrustedClientContext::ATTRIBUTE => $request->attributes->get(TrustedClientContext::ATTRIBUTE), 'fms.required-operation' => $request->attributes->get('fms.required-operation')];
        $request->setUserResolver(static fn () => $actor);
        $request->attributes->set(TrustedClientContext::ATTRIBUTE, new TrustedClientContext($this->principal, $this->tenant));
        $request->attributes->set('fms.required-operation', ['workspace' => $this->workspace, 'operation' => $operation]);
        try {
            return $callback($scope, $actor);
        } finally {
            $request->setUserResolver($resolver);
            foreach ($prior as $key => $value) {
                if ($value === null) {
                    $request->attributes->remove($key);
                } else {
                    $request->attributes->set($key, $value);
                }
            }
        }
    }

    public function entry(object $scope, string $id, bool $trashed = false): object
    {
        $row = app(Entries::class)->resolve($scope->id, $id);
        abort_if(! $trashed && $row->trashed_at !== null, 404);
        abort_unless(app(SourcePolicy::class)->visible($scope, $this->principal, [$row]) !== [], 404);
        app(SourcePolicy::class)->readable($scope, $this->principal, $row);

        return $row;
    }
}
