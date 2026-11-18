<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Members;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use LogicException;

/** Applications supply a scoped query; Studio never exposes a global user directory by default. */
final class MemberDirectory
{
    public function query(Request $request, object $workspace): Builder
    {
        $provider = config('media-studio.members.directory');
        abort_unless(is_string($provider) && $provider !== '', 422, 'Configure a scoped member directory first.');
        $directory = app($provider);
        if (! $directory instanceof MemberDirectoryScope) {
            throw new LogicException('The member directory must implement MemberDirectoryScope.');
        }
        $query = $directory->query($request->user(), $workspace);
        $model = $query->getModel();
        if (! $model instanceof Authenticatable || ! in_array($model::class, config('media-studio.principals', []), true)) {
            throw new LogicException('The directory must use a registered authenticatable principal model.');
        }

        return $query;
    }
}
