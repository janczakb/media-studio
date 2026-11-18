<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Library;

use Carbon\CarbonImmutable;
use Illuminate\Database\Query\Builder;
use Illuminate\Http\Request;
use UseFlex\MediaStudio\Identity\Principal;

final readonly class PhotosOptions
{
    private function __construct(public string $kind, public string $period, public ?string $bucket) {}

    public static function from(Request $request, string $view): self
    {
        $data = $request->validate([
            'photos' => ['nullable', 'array:kind,period,bucket'],
            'photos.kind' => ['nullable', 'in:all,image,video,starred'],
            'photos.period' => ['nullable', 'in:years,months,days'],
            'photos.bucket' => ['nullable', 'string', 'max:10'],
        ]);
        $photos = $data['photos'] ?? [];
        abort_if($view !== 'photos' && $photos !== [], 422, 'Photo timeline options apply only to Photos.');
        $kind = $photos['kind'] ?? 'all';
        $period = $photos['period'] ?? 'days';
        $bucket = $photos['bucket'] ?? null;
        if ($bucket !== null) {
            $pattern = match ($period) {
                'years' => '/\A[1-9][0-9]{3}\z/',
                'months' => '/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])\z/',
                default => '/\A[1-9][0-9]{3}-(?:0[1-9]|1[0-2])-(?:0[1-9]|[12][0-9]|3[01])\z/',
            };
            abort_unless(preg_match($pattern, $bucket), 422, 'Enter a valid photo timeline period.');
            $date = $bucket.($period === 'years' ? '-01-01' : ($period === 'months' ? '-01' : ''));
            abort_unless(CarbonImmutable::canBeCreatedFromFormat($date, 'Y-m-d'), 422, 'Enter a valid photo timeline date.');
            $parsed = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            abort_unless($parsed->format('Y-m-d') === $date, 422, 'Enter a valid photo timeline date.');
        }

        return new self($kind, $period, $bucket);
    }

    public function apply(Builder $query, object $workspace, Principal $principal): void
    {
        $query->where('photo_media', 1)->whereNull('trashed_at');
        if (in_array($this->kind, ['image', 'video'], true)) {
            $query->where('media_type', $this->kind);
        } elseif ($this->kind === 'starred') {
            $query->whereIn('fms_entries.id', fn (Builder $personal) => $personal->select('entry_id')->from('fms_personal_entries')
                ->where('workspace_id', $workspace->id)->where('principal_key', $principal->key())->whereNotNull('starred_at'));
        }
        if ($this->bucket !== null) {
            $date = $this->bucket.($this->period === 'years' ? '-01-01' : ($this->period === 'months' ? '-01' : ''));
            $from = CarbonImmutable::createFromFormat('!Y-m-d', $date, 'UTC');
            $until = match ($this->period) {
                'years' => $from->addYear(),
                'months' => $from->addMonth(),
                default => $from->addDay(),
            };
            $timezone = (string) config('app.timezone', 'UTC');
            $query->where('photo_created', '>=', $from->setTimezone($timezone)->format('Y-m-d H:i:s'))
                ->where('photo_created', '<', $until->setTimezone($timezone)->format('Y-m-d H:i:s'));
        }
    }

    public function order(Builder $query): void
    {
        $query->orderByDesc('photo_created')->orderByDesc('id');
    }

    public function scope(): array
    {
        return [$this->kind, $this->period, $this->bucket];
    }
}
