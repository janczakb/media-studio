---
title: "Agent workflows and verification"
description: "Use focused prompts for pickers, uploads and processing, then check the installed contract."
---

Start by asking the agent to activate `media-studio-development` and inspect the
installed package versions. Include the integration boundary and constraints in
the request. The skill is a maintained guide, not permission to alter production
storage, run paid AI calls or publish your packages.

## Build a native Filament picker

```text
Use media-studio-development. Add a StudioMediaPicker to the product form.
Reuse existing Studio media without another upload. Allow JPEG/PNG/WebP only,
maximum six files, fixed grid layout, downloads disabled. Resolve the workspace
from the current tenant on the server. Verify the installed field methods and
show tests for wrong-tenant access and a removed selected asset.
```

The expected boundary is Studio's native Filament adapter. FLEX Fields should not
be installed to satisfy this request. For the opposite requirement, explicitly
ask for the existing FLEX `FlexFileUpload::mediaStudio()` integration and preserve
its native field appearance/asset lifecycle.

## Configure optional thumbnails

```text
Use media-studio-development. Configure first-page PDF thumbnails in command mode
with a bounded scheduled batch. Keep originals private on our configured S3 disk.
Check the installed PDF generator settings and executable requirements. Show how
pending work, failures and an existing-library backfill will be handled.
```

The agent should distinguish document thumbnails from browser preview and OCR,
check Poppler/libvips capability, and avoid generating thumbnails in a list request.
See [document thumbnails](/document-thumbnails) for the actual implementation.

## Configure AI without enabling unrelated systems

```text
Use media-studio-development. Enable image OCR only, using the supported adapter,
with explicit single-file testing before automatic uploads. Leave visual tagging
and the assistant disabled. Keep provider keys server-side and explain input,
page/time/character limits and retry costs.
```

The skill separates `search.content.ocr`, `intelligence`, Meilisearch embeddings
and `assistant`. Configuring one should not switch on the others or claim
exactly-once billing.

## Review the generated result

Require actual installed method signatures, durable reference persistence,
server-side permission/tenant checks and the relevant negative cases. For
frontend changes, verify strict TypeScript, mount/unmount lifecycle and the real
interaction. For PHP fields, test saved state after deletion/replacement of media.
Separate simulated provider behavior from a real cloud test, and report limits
instead of claiming universal performance or complete AI understanding.

For product runtime operations, use [MCP](/mcp) with its own credentials and
permissions. Boost development instructions do not grant access to that API.
