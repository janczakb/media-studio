---
title: "AI workers, limits and troubleshooting"
description: "Deploy bounded AI work, diagnose pending operations and distinguish processing retries from provider costs."
---

Keep visual analysis, OCR/extraction and chat on explicit queues. Run only the
workers for the features you enabled. All examples assume a configured Redis
connection; use your application's actual asynchronous connection name.

| Work | Default queue | Runtime behavior |
| --- | --- | --- |
| Automatic visual analysis | `media-intelligence` | Job timeout 570 s, shared lock lease 600 s, one attempt |
| Automatic text/OCR extraction | `media-content` | Job timeout 390 s, lock lease 420 s, one attempt |
| Assistant runs | `media-studio-ai` | Job timeout 180 s; provider stream timeout 120 s |

For example, configure separate workers with finite attempts and timeouts:

```bash
php artisan queue:work redis --queue=media-intelligence --timeout=580 --tries=1
php artisan queue:work redis --queue=media-content --timeout=400 --tries=1
php artisan queue:work redis --queue=media-studio-ai --timeout=190 --tries=1
```

Set the selected connection's retry-after greater than the largest worker timeout
and applicable lock lease; for these examples it must exceed 600 seconds. If
other jobs require a shorter retry interval, give this work its own Laravel queue
connection rather than changing a shared connection blindly. Configure finite
storage/provider network timeouts as well.

A `sync` connection is not a background worker. Automatic analysis/extraction
intentionally does not run paid processing inside an upload request; the assistant
is unavailable without a supported asynchronous connection.

## Admission and cost control

- `max_pending_per_workspace` bounds outstanding visual/content admission; the shipped default is 100 for each subsystem.
- Source byte/pixel/duration/frame/page caps bound individual inputs.
- Assistant `max_active_runs` limits active responses; model `usage_weight` is a relative display/usage weight, not a monetary budget.
- Provider quotas and spending limits belong to your provider account and deployment.

A timed-out HTTP request may already have been billed. Ready identical
version/profile results are reused where implemented, but an explicit retry after
an ambiguous failure can repeat charges. Automatic analysis has no durable
exactly-once billing outbox or automatic whole-library backfill. Do not describe
those as delivered features.

## Persistence and cancellation

Queued/running/result states survive an ordinary browser reload. A worker or
network failure is different: a killed process may require operator intervention
or a new explicit attempt. The assistant checks for stale active runs on subsequent
conversation access; it does not guarantee immediate recovery at the moment of a
server crash. Stop cannot undo a committed mutation.

Use [assistant history](/assistant-history), [retention](/assistant-retention) and
[scheduled maintenance](/queues-and-scheduler). File deletion/replacement makes
stale versions ineligible for publication. Engine synchronization and retained
SQL projections have their own lifecycles; disabling a switch is not bulk deletion.

## Diagnose by subsystem

| Symptom | Inspect |
| --- | --- |
| Assistant is hidden | enabled, installed Laravel AI binding, valid model definitions and async queue connection |
| Chat stays pending | Exact connection/queue name, live worker, provider credentials/model and failed-job state |
| Visual labels absent | intelligence switch, images/videos switch, source ownership, input strategy, source caps and optional migrations |
| Input waits or skips | A requested rendition is missing; wait/skip intentionally avoids a provider call |
| OCR absent | search.content and OCR switches, supported MIME, Vision key, extraction limits; visual analysis flags are independent |
| Scanned PDF fails | Poppler executable, source cap, per-page deadline and OCR provider availability |
| Semantic results unavailable | Meilisearch settings task, valid embedder configuration, completed indexing and current fingerprints |
| Different user sees no result | Check workspace/tenant/source access and review permissions; do not bypass them to fill a result list |
| Replaced file shows no fresh result | New current version needs its own admitted analysis/extraction/index update |

Use [configuration defaults](/ai/configuration) and the relevant capability guide
before increasing any limit. Keep provider credentials, raw document content and
private attachments out of public logs. Encryption at rest does not make external
AI processing local: selected inputs must be available to the configured provider.
