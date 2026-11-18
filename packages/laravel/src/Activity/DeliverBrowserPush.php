<?php

declare(strict_types=1);

namespace UseFlex\MediaStudio\Activity;

use Illuminate\Console\Command;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use JsonException;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;
use UseFlex\MediaStudio\Access\WorkspaceAccess;
use UseFlex\MediaStudio\Identity\Principal;
use UseFlex\MediaStudio\Identity\TenantIdentity;

final class DeliverBrowserPush extends Command
{
    protected $signature = 'media-studio:notifications:deliver {--limit=100}';

    protected $description = 'Deliver pending Studio desktop notifications';

    public function handle(WorkspaceAccess $access, PushDelivery $sender): int
    {
        if (! BrowserPush::available()) {
            return self::SUCCESS;
        }

        $now = now();
        $expired = DB::table('fms_push_deliveries')->where('expires_at', '<=', $now)
            ->orderBy('expires_at')->limit(500)->pluck('id');
        if ($expired->isNotEmpty()) {
            DB::table('fms_push_deliveries')->whereIn('id', $expired)->delete();
        }

        $deadline = hrtime(true) + 45_000_000_000;
        $ids = DB::table('fms_push_deliveries')->where('available_at', '<=', $now)->where('expires_at', '>', $now)
            ->orderBy('available_at')->orderBy('id')->limit(max(1, min(500, (int) $this->option('limit'))))->pluck('id');

        foreach ($ids as $id) {
            if (hrtime(true) >= $deadline) {
                break;
            }

            $lease = now()->addMinute();
            $claimed = DB::table('fms_push_deliveries')->where('id', $id)->where('available_at', '<=', $now)
                ->update(['available_at' => $lease, 'attempts' => DB::raw('attempts + 1')]);
            if ($claimed !== 1) {
                continue;
            }

            $pending = DB::table('fms_push_deliveries')->where('fms_push_deliveries.id', $id)->where('fms_push_deliveries.available_at', $lease);
            $row = (clone $pending)->join('fms_notifications as n', 'n.id', '=', 'fms_push_deliveries.notification_id')
                ->join('fms_activity as a', 'a.id', '=', 'n.activity_id')
                ->join('fms_workspaces as w', 'w.id', '=', 'n.workspace_id')
                ->join('fms_push_devices as d', 'd.id', '=', 'fms_push_deliveries.device_id')
                ->whereColumn('d.principal_key', 'n.principal_key')->whereNull('n.read_at')
                ->first(['fms_push_deliveries.*', 'a.event', 'w.id as workspace_id', 'w.public_id as workspace_public_id',
                    'w.tenant_type', 'w.tenant_id', 'w.tenant_key', 'd.subscription', 'd.return_path', 'd.principal_key', 'd.locale']);
            if ($row === null || (int) $row->attempts > 4) {
                $pending->delete();

                continue;
            }

            try {
                $status = $this->deliver($row, $access, $sender);
            } catch (Throwable) {
                $this->warn('A desktop notification delivery failed and will be retried if eligible.');
                $status = 0;
            }

            if (in_array($status, [404, 410], true)) {
                DB::table('fms_push_devices')->where('id', $row->device_id)
                    ->where('principal_key', $row->principal_key)->where('subscription', $row->subscription)->delete();
            }
            if ((int) $row->attempts < 4 && ($status === 0 || $status === 429 || $status >= 500)) {
                $pending->update(['available_at' => now()->addSeconds(60 * 4 ** ((int) $row->attempts - 1))]);
            } else {
                $pending->delete();
            }
        }

        return self::SUCCESS;
    }

    private function deliver(object $row, WorkspaceAccess $access, PushDelivery $sender): int
    {
        $principal = new Principal($row->principal_type, $row->principal_id);
        if ($principal->key() !== $row->principal_key
            || ! in_array($row->event, BrowserPush::events((int) $row->workspace_id, $row->principal_key), true)) {
            return 204;
        }

        try {
            $access->resolveForWorker($principal, $row->workspace_public_id, TenantIdentity::fromWorkspace($row), operation: 'notifications.view');
        } catch (HttpExceptionInterface $error) {
            if (in_array($error->getStatusCode(), [401, 403, 404], true)) {
                return 204;
            }
            throw $error;
        }

        try {
            $subscription = json_decode(Crypt::decryptString($row->subscription), true, flags: JSON_THROW_ON_ERROR);
        } catch (DecryptException|JsonException) {
            return 410;
        }
        if (! is_string($subscription['endpoint'] ?? null) || ! BrowserPush::acceptsEndpoint($subscription['endpoint'])) {
            return 410;
        }

        $ttl = (int) min(1800, now()->diffInSeconds(Carbon::parse($row->expires_at), false));
        if ($ttl <= 0) {
            return 204;
        }

        $translator = app('translator');
        $locale = 'en';
        foreach (array_unique([$row->locale, explode('-', $row->locale)[0], 'en']) as $candidate) {
            if ($translator->hasForLocale('media-studio::push.message', $candidate)) {
                $locale = $candidate;
                break;
            }
        }

        return $sender->send($subscription, [
            'workspace' => $row->workspace_public_id,
            'path' => $row->return_path,
            'body' => $translator->get('media-studio::push.message', [], $locale, false),
        ], $ttl);
    }
}
