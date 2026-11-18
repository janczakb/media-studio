<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Navigation;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use LogicException;
use Throwable;

final class MaintainNavigation extends Command
{
    protected $signature = 'media-studio:navigation:maintain';

    protected $description = 'Deliver a bounded navigation signal batch and expire navigation receipts and replay history.';

    public function handle(): int
    {
        $publisher = config('media-studio.navigation.publisher');
        if ($publisher !== null) {
            $publisher = app($publisher);
            if (! $publisher instanceof NavigationPublisher) {
                throw new LogicException('Navigation publisher must implement NavigationPublisher.');
            }
            $rows = DB::table('fms_navigation_changes')->whereNull('published_at')
                ->where(fn ($query) => $query->whereNull('available_at')->orWhere('available_at', '<=', now()))
                ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                ->orderBy('id')->limit(100)->get(['id', 'workspace_id', 'revision', 'attempts']);
            foreach ($rows as $row) {
                $token = (string) Str::uuid();
                $claimed = DB::table('fms_navigation_changes')->where('id', $row->id)->whereNull('published_at')
                    ->where(fn ($query) => $query->whereNull('lease_until')->orWhere('lease_until', '<=', now()))
                    ->update(['lease_token' => $token, 'lease_until' => now()->addMinutes(2), 'attempts' => DB::raw('attempts + 1')]);
                if (! $claimed) {
                    continue;
                }
                try {
                    $workspace = DB::table('fms_workspaces')->where('id', $row->workspace_id)->value('public_id');
                    if ($workspace !== null) {
                        $publisher->publish($workspace, (int) $row->revision);
                    }
                    $changes = ['published_at' => now(), 'lease_until' => null, 'lease_token' => null];
                } catch (Throwable $error) {
                    report($error);
                    $changes = ['available_at' => now()->addSeconds(min(3600, 5 * (2 ** min(9, (int) $row->attempts)))), 'lease_until' => null, 'lease_token' => null];
                }
                DB::table('fms_navigation_changes')->where('id', $row->id)->where('lease_token', $token)->update($changes);
            }
        }
        $days = max(1, min(90, (int) config('media-studio.navigation.journal_days', 7)));
        $ids = DB::table('fms_navigation_changes')->where('created_at', '<', now()->subDays($days))->orderBy('created_at')->limit(1000)->pluck('id');
        DB::table('fms_navigation_changes')->whereIn('id', $ids)->delete();
        $ids = DB::table('fms_navigation_operations')->where('expires_at', '<', now())->orderBy('expires_at')->limit(1000)->pluck('id');
        DB::table('fms_navigation_operations')->whereIn('id', $ids)->delete();
        $this->info('Navigation maintenance batch completed.');

        return self::SUCCESS;
    }
}
