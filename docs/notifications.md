---
title: "In-app and desktop notifications"
description: "Configure the inbox, browser push and notification delivery."
---

Start with the in-app inbox. Enable browser push only for origins and deployments that need background notifications.

## Notifications

The workspace inbox stores Studio activity for the user who initiated it. Upload, completed image processing, trash, and restoration events survive a page reload. Mark a single event read or unread, or mark the current inbox read. Keep activity history while hiding the inbox by setting `notifications.enabled` to `false`.

```php
return [
    // Other Studio settings...
    'activity' => ['enabled' => true],
    'notifications' => ['enabled' => true],
];
```

### Desktop notifications

Turn on **Desktop notifications** in the top-right corner of the Notifications
page, or open **Settings → Notifications**. Your browser asks for permission after
you turn the switch on. The switch controls this browser across your workspaces.

In Settings, select the event types you want to receive for the current workspace.
Preferences are saved immediately and apply to your subscribed devices. Mentions,
guest uploads and completed processing are selected initially. You can also enable
your own uploads, moves to trash, restorations and permanent deletions. These
preferences control desktop delivery. Your Studio inbox keeps its existing events.

Desktop notifications are optional and disabled in the server configuration by
default. Install the delivery libraries in your Laravel application.

```bash
composer require 'minishlink/web-push:^11.0' 'guzzlehttp/guzzle:^7.9|^8.0'
```

Generate a VAPID key pair once.

```bash
php -r 'require "vendor/autoload.php"; echo json_encode(Minishlink\WebPush\VAPID::createVapidKeys(), JSON_PRETTY_PRINT);'
```

Save the public and private key in your application's secret configuration.
The private key remains on the server. For an environment-based setup, use these
variables in your application's environment file.

```dotenv
FMS_WEB_PUSH_ENABLED=true
FMS_WEB_PUSH_PUBLIC_KEY=YOUR_PUBLIC_KEY
FMS_WEB_PUSH_PRIVATE_KEY=YOUR_PRIVATE_KEY
FMS_WEB_PUSH_SUBJECT=mailto:admin@example.com
```

Publish the updated Studio migrations and migrate your application's database.

```bash
php artisan vendor:publish --tag=media-studio-migrations
php artisan migrate
```

Serve Studio and its API on the same HTTPS origin. Studio registers its dedicated
push service worker only when a user enables notifications. The worker uses its own
narrow scope and handles push messages and clicks.

The package registers a delivery command with Laravel's scheduler every ten
seconds when push is enabled. Enable your application's normal Laravel scheduler.
Delivery also works without a queue worker. To drain one batch manually, run the
following command.

```bash
php artisan media-studio:notifications:deliver --limit=100
```

To manage scheduling yourself, set `notifications.push.schedule` to `false`.
The command sends at most 500 messages per batch, stops admitting work after
45 seconds, retries temporary errors up to four times, and discards unsent
messages after six hours. Configure your scheduler to avoid overlapping runs.

The service checks the recipient's current `notifications.view` permission,
workspace membership and tenant access before delivery. Read notifications and
disabled event types are skipped. Disabling the device removes its pending
deliveries. Expired browser subscriptions are removed automatically.

Desktop messages show a generic notification and open the relevant workspace
inbox when clicked. They omit file names and comment text. The inbox requires
authentication and current access to the workspace. Delivery timing also depends
on the browser, connectivity and operating-system notification settings.

Custom React transports can provide `ManagementTransport.browserPush`. The
exported `BrowserPushTransport` type describes status, device subscription,
unsubscription and event preferences. `createHttpTransport()` includes the
Laravel implementation.

## Notification inbox

The inbox automatically marks currently visible unread rows as read using one
bounded request (maximum 120 IDs); overscan rows are excluded. This requires
`notifications.manage`. Notification names open an available file preview.
Author identity is resolved in a batch and timestamps use relative time.
The primary rail shows a count capped at `9+`. Unread summaries have a 15-second
scoped cache, invalidated after activity and read operations; client page caches
retain at most eight pages and preserve the list during refresh.
The existing inbox check still runs every 15 seconds in a visible tab and on
returning to the tab; this update does not replace it with push delivery.
