<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Members;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder;
use UseFlex\MediaStudio\Identity\AttributeMemberProfileResolver;
use UseFlex\MediaStudio\Identity\MemberProfileResolver;
use UseFlex\MediaStudio\Identity\Principal;

final class WorkspaceMemberSearch
{
    public function apply(Builder $members, Principal $principal, Authenticatable $viewer, string $search): void
    {
        $attribute = config('media-studio.members.search_attribute', 'name');
        abort_unless(is_string($attribute) && preg_match('/\A[a-zA-Z_][a-zA-Z0-9_]*\z/', $attribute), 422);
        $term = mb_strtolower($search, 'UTF-8');
        $pattern = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $term).'%';
        $connection = $members->getConnection();
        $cast = in_array($connection->getDriverName(), ['mysql', 'mariadb'], true) ? 'CHAR' : 'TEXT';
        $grammar = $connection->getQueryGrammar();
        $resolver = app(config('media-studio.member_profiles.resolver', AttributeMemberProfileResolver::class));
        abort_unless($resolver instanceof MemberProfileResolver, 500);

        $members->where(function (Builder $matching) use ($attribute, $cast, $connection, $grammar, $pattern, $principal, $resolver, $term, $viewer): void {
            $matching->whereRaw('1 = 0');
            $viewerName = $resolver->resolve($viewer)['name'] ?? '';
            if (str_starts_with(mb_strtolower($viewerName, 'UTF-8'), $term)) {
                $matching->orWhere('principal_key', $principal->key());
            }
            foreach ((array) config('media-studio.principals', []) as $type => $class) {
                if (! is_string($class) || ! is_subclass_of($class, Model::class) || ! is_subclass_of($class, Authenticatable::class)) {
                    continue;
                }
                $model = new $class;
                if ($model->getConnection() !== $connection || ! $connection->getSchemaBuilder()->hasColumn($model->getTable(), $attribute)) {
                    continue;
                }
                $key = $grammar->wrap($model->qualifyColumn($model->getAuthIdentifierName()));
                $name = $grammar->wrap($model->qualifyColumn($attribute));
                $users = $model->newQuery()->selectRaw("CAST({$key} AS {$cast})")
                    ->whereRaw("LOWER({$name}) LIKE ? ESCAPE '!'", [$pattern]);
                $matching->orWhere(fn (Builder $scope) => $scope->where('principal_type', $type)->whereIn('principal_id', $users));
            }
        });
    }
}
