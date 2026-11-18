---
title: Share and publish analytics
description: Enable optional link analytics and interpret visits, duration and country totals.
---

Enable **Collect analytics** when creating or editing a link. Open **Manage link** or **Shared** to see the report. Inspect a short range for a new campaign and use a longer range to compare weekly activity.

## Interpret the metrics

| Metric | Meaning |
| --- | --- |
| Visits | Eligible visits to the root of the guest page. Nested folder navigation, pagination and recognized prefetch requests are excluded. Repeat visits can count separately. |
| Average duration | Based on visits that reported a duration, with a maximum recorded duration of 30 minutes per visit. A day without a reported duration has no average. |
| Countries | The leading country totals from an optional trusted ingress header. Visits without a usable country still contribute to visits. |

Days are grouped in UTC. An enrolled link with no visits shows an empty series of counts. Turning collection on starts recording future activity.

## Configure country totals

Set `sharing.trusted_country_header` only when your ingress overwrites that header for every request. For example, use a header called `X-Verified-Country` after the ingress is configured to supply it and to discard values from clients.

```php
'sharing' => [
    // Keep your other share settings.
    'trusted_country_header' => 'X-Verified-Country',
],
```

Leave the setting unset in a deployment without a trusted country header. Configure a shared Laravel cache across application instances for duration receipt deduplication.

## Read a report from the Studio API

```http
GET /media-studio/api/v1/workspaces/{workspace}/shares/{share}/analytics?days=7
```

Request 7, 30 or 90 days. The default is 30. The response includes `points`, `visits`, `measuredVisits`, `averageSeconds`, `lastVisitedAt` and `countries`. Studio verifies the caller's permission to edit the link before returning its report.
