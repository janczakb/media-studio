---
title: "Upload recovery and cleanup"
description: "Run session cleanup and recover interrupted ordinary writes."
---

Studio tracks ordinary upload receipts and resumable sessions separately. Use the recovery workflow that matches the type of transfer.

## Schedule staging cleanup

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:uploads:prune --limit=100')
    ->everyTenMinutes()
    ->withoutOverlapping();
```

Run the Laravel scheduler on the application. The command removes staging data
for completed, cancelled and expired sessions in batches. Accepted originals are
kept.

## Recover interrupted upload writes

An ordinary upload records its storage attempt before sending bytes. Repeating
the same accepted request returns its existing file. A second request while the
first write is active receives HTTP409. After the write lease expires, repeating
the request creates a fresh attempt and prevents the expired writer from publishing.

```php
'upload' => [
    'receipts' => [
        'lease_seconds' => 300,
        'cleanup_retry_seconds' => 3600,
    ],
],
```

Set the lease long enough for the application's largest permitted storage write.
This lease also covers publication of an assembled local chunk upload. The
server enforces a range of 30 to 86,400 seconds for the lease and 60 to 86,400
seconds between cleanup attempts.

Run the bounded cleanup command periodically through your application's scheduler.
This example inspects up to 100 due attempts each minute.

```php
use Illuminate\Support\Facades\Schedule;

Schedule::command('media-studio:uploads:prune-attempts --limit=100')->everyMinute();
```

Cleanup checks the attempt's exact object identity, storage target and accepted
version before deletion. Failed deletions remain scheduled. An expired writer
whose final state is unknown keeps a scheduled record so a later pass can remove
bytes that arrive after the first deletion. Existing receipts from before the
attempt-tracking migration can be retried after the lease interval. Their old
unrecorded storage paths require a separate migration audit.
