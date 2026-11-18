---
name: media-studio-development
description: Integrate and configure FLEX Media Studio in Laravel, React, Filament or optional FLEX Fields; use for media references, uploads, pickers, image versions, thumbnails, permissions, tenants, AI/search, keyboard shortcuts and Studio APIs.
---

# Media Studio development

Use this skill for code consuming or extending Media Studio. It is development
context, not an MCP credential, permission to access a library, or authorization
to install providers, migrate production data, publish packages or spend on AI.

## Establish the installed contract

1. Check `composer show useflex/media-studio`; inspect optional package versions
   only when using their adapter. Core requires Spatie Permission v8; Media Library
   v11, Filament, FLEX Fields, Laravel AI and MCP are independent options.
2. Find the installed package with
   `Composer\InstalledVersions::getInstallPath('useflex/media-studio')`. Inspect its
   `config/media-studio.php`, PHP class signatures and frontend declaration files.
   Package source layout differs from an application's `vendor` layout: do not
   hardcode a development checkout or assume the sibling React package is shipped
   in every Composer distribution.
3. Read only the relevant reference:
   - [Integration contracts](references/integrations.md): React embedding,
     Filament fields, optional FLEX Fields, persistence and version publication.
   - [Storage and processing](references/storage-processing.md): private storage,
     conversion recipes, PDF/video thumbnails, upload and deletion lifecycles.
   - [Access and optional AI](references/access-ai.md): principal/tenant scope,
     permissions, OCR, semantic retrieval and assistant boundaries.
4. Current public documentation: https://studio.bjanczak.com/ . Prefer the
   installed source when documentation and the deployed package version differ.
   Never fabricate a method or claim a provider works because its switch exists.

## Non-negotiable integration boundaries

- A selected media reference is not another upload. Preserve asset/version IDs;
  never persist a temporary signed URL as the durable identity.
- Host records can reference shared library media. Removing a form attachment
  does not grant permission to delete a shared original. Re-resolve unavailable
  selections and keep valid references instead of rendering broken thumbnails.
- Resolve principal, tenant and workspace on the server. UI visibility, MIME
  filters and a client-supplied workspace ID are not authorization.
- Source adapters retain ownership. Do not apply native overwrite/delete jobs to
  Spatie/application/external media unless the adapter explicitly permits it.
- Use thumbnails/renditions in grid/list UI. Keep indexed keyset pagination,
  virtualized bounded windows, stable row identities and cancellation. A million
  entries is not a reason to load the whole tree or bucket into memory.
- Optional generation runs outside listing requests. AI flags are independent;
  never enable billable automatic processing as a side effect of adding chat.
- Classic/AI image edits are candidates until explicitly accepted. Publication
  validates current revision/ownership, creates the authoritative version and
  admits configured derivatives. History retention and immediate replacement
  are configuration policies, not a checkbox invented in the product UI.

## Implement and verify the requested slice

Use host-native fields and the existing Studio SDK. Choose the smallest documented
extension point; do not add Filament/FLEX dependencies to core. Keep assets scoped
and load large editors on demand. Keyboard/clipboard commands belong to the active
Studio instance; preserve native text input, permission checks, upload limits and
unmount cleanup.

Test actual behavior, including denied access, wrong workspace/tenant, stale
revision, removed selection and disabled optional capability where applicable.
For an SDK/component change run strict TypeScript and relevant client/browser
checks; for PHP adapters test persistence and authorization. Describe what was
observed, what was mocked and which provider/storage flows remain unverified.
