<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Sharing;

use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class ShareEngagement
{
    private const STRIPES = 16;

    private const MAX_SECONDS = 1800;

    public function visit(Request $request, object $share): ?string
    {
        if (! $share->collect_analytics || ! $request->isMethod('GET') || $request->has('parent') || $request->has('cursor')) {
            return null;
        }
        if (str_contains(strtolower((string) $request->header('Sec-Purpose')), 'prefetch')) {
            return null;
        }

        $now = now()->utc();
        $day = $now->toDateString();
        $stripe = random_int(0, self::STRIPES - 1);
        $key = ['share_id' => $share->id, 'day' => $day, 'stripe' => $stripe];

        DB::table('fms_share_daily')->insertOrIgnore([
            ...$key, 'visits' => 0, 'duration_seconds' => 0, 'measured_visits' => 0,
        ]);
        DB::table('fms_share_daily')->where($key)->increment('visits');
        DB::table('fms_share_daily')->where($key)->where(fn ($query) => $query->whereNull('last_visited_at')->orWhere('last_visited_at', '<', $now))
            ->update(['last_visited_at' => $now]);

        // Configure only behind an ingress that overwrites this header on every request.
        $header = config('media-studio.sharing.trusted_country_header');
        $country = is_string($header) && $header !== '' ? strtoupper((string) $request->header($header)) : '';
        if (preg_match('/\A[A-Z]{2}\z/', $country) && ! in_array($country, ['XX', 'ZZ'], true)) {
            $countryKey = ['share_id' => $share->id, 'day' => $day, 'country' => $country];
            DB::table('fms_share_countries')->insertOrIgnore([...$countryKey, 'visits' => 0]);
            DB::table('fms_share_countries')->where($countryKey)->increment('visits');
        }

        return Crypt::encryptString(json_encode([$share->public_id, (int) $share->revision, $day, $stripe, $now->timestamp, bin2hex(random_bytes(16))], JSON_THROW_ON_ERROR));
    }

    public function duration(object $share, string $ticket, int $seconds): void
    {
        if (! $share->collect_analytics) {
            return;
        }
        try {
            $payload = json_decode(Crypt::decryptString($ticket), true, 8, JSON_THROW_ON_ERROR);
        } catch (DecryptException|\JsonException) {
            return;
        }
        if (! is_array($payload) || count($payload) !== 6) {
            return;
        }
        [$id, $revision, $day, $stripe, $started, $nonce] = $payload;
        $elapsed = now()->timestamp - $started;
        if ($id !== $share->public_id || $revision !== (int) $share->revision || $elapsed < 0 || $elapsed > self::MAX_SECONDS + 300) {
            return;
        }
        $seconds = min($seconds, self::MAX_SECONDS, $elapsed + 1);
        if ($seconds < 1 || ! Cache::add('fms-share-duration:'.$nonce, true, self::MAX_SECONDS + 300)) {
            return;
        }

        DB::table('fms_share_daily')->where(['share_id' => $share->id, 'day' => $day, 'stripe' => $stripe])
            ->incrementEach(['duration_seconds' => $seconds, 'measured_visits' => 1]);
    }

    public function report(object $share, int $days): array
    {
        $end = now()->utc()->startOfDay();
        $start = $end->copy()->subDays($days - 1);
        $dates = [$start->toDateString(), $end->toDateString()];
        $buckets = DB::table('fms_share_daily')->where('share_id', $share->id)->whereBetween('day', $dates)
            ->selectRaw('day, SUM(visits) AS visits, SUM(duration_seconds) AS duration_seconds, SUM(measured_visits) AS measured_visits, MAX(last_visited_at) AS last_visited_at')
            ->groupBy('day')->get()->keyBy('day');
        $points = [];
        for ($day = $start->copy(); $day->lte($end); $day->addDay()) {
            $key = $day->toDateString();
            $bucket = $buckets->get($key);
            $samples = (int) ($bucket->measured_visits ?? 0);
            $points[] = [
                'date' => $key,
                'visits' => (int) ($bucket->visits ?? 0),
                'averageSeconds' => $samples ? (int) round($bucket->duration_seconds / $samples) : null,
            ];
        }
        $measured = (int) $buckets->sum('measured_visits');
        $latest = $buckets->max('last_visited_at');
        $countries = DB::table('fms_share_countries')->where('share_id', $share->id)->whereBetween('day', $dates)
            ->selectRaw('country, SUM(visits) AS visits')->groupBy('country')->orderByDesc('visits')->orderBy('country')->limit(8)->get();

        return [
            'points' => $points,
            'visits' => (int) $buckets->sum('visits'),
            'measuredVisits' => $measured,
            'averageSeconds' => $measured ? (int) round($buckets->sum('duration_seconds') / $measured) : null,
            'lastVisitedAt' => $latest ? Carbon::parse($latest, 'UTC')->toIso8601String() : null,
            'countries' => $countries->map(fn (object $row): array => ['country' => $row->country, 'visits' => (int) $row->visits])->all(),
        ];
    }
}
