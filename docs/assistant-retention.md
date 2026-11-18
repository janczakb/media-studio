---
title: "Assistant retention"
description: "Configure bounded cleanup of inactive and deleted assistant conversations."
---

Set a retention policy only after reviewing which chat history and chat attachments the workspace needs to keep.

## Optional old-chat retention

```php
'assistant' => [
    // Keep your other assistant options.
    'retention' => [
        'enabled' => false,
        'inactive_days' => 90,
        'deleted_days' => 30,
        'batch' => 50,
    ],
],
```

Retention is off by default. When enabled, `media-studio:assistant:maintain`
soft-deletes inactive conversations after `inactive_days`, then permanently
removes deleted conversations after the additional `deleted_days` period.
Active runs are excluded. Each pass is bounded by `batch` (maximum 500);
attachment cleanup must finish before permanent removal. Files saved in the
library are independent of chat attachment cleanup. Run Laravel's scheduler;
the provider schedules assistant maintenance when the assistant is enabled.
Apply core migration `000052_workspace_limits` and optional assistant migration
`000009_assistant_retention` when upgrading this development checkout.
