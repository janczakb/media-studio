---
title: "Transfers and integration configuration reference"
description: "Every shipped setting in this area, its default, purpose and related implementation guide."
---

Defaults below are from the package configuration with environment variables unset. Paths are relative to `media-studio`: for example, `storage.disk` means `config('media-studio.storage.disk')`. Empty arrays are extension points, not automatically discovered registrations.

See the [configuration guide](/configuration) for deployment steps and the [coverage and feature matrix](/configuration-reference) for scope.

## Api

Examples and operational requirements: [read the guide](/api/introduction).

| Setting | Default | Behavior |
| --- | --- | --- |
| `api.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `api.path` | `"media-studio/rest/v1"` | Relative REST API route prefix. |
| `api.requests_per_minute` | `120` | REST request rate limit per configured client identity. |
| `api.max_token_days` | `90` | Maximum REST token lifetime in days. |
| `api.usage_cache_seconds` | `10` | Usage-accounting cache interval in seconds. |
## Mcp

Examples and operational requirements: [read the guide](/mcp-access).

| Setting | Default | Behavior |
| --- | --- | --- |
| `mcp.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `mcp.path` | `"media-studio/mcp"` | Relative MCP endpoint path. |
| `mcp.middleware` | `["auth:sanctum"]` | Middleware applied to the MCP endpoint; default requires Sanctum authentication. |
| `mcp.authentication` | `"bearer"` | MCP authentication strategy; bearer is the default. |
| `mcp.connections` | `[]` | Explicit named MCP connection definitions; see MCP access examples. |
| `mcp.connection_middleware` | `[]` | Middleware for the configured connection flow. |
| `mcp.origins` | `null` | Optional MCP origin allowlist; null leaves this restriction unconfigured. |
| `mcp.workspaces` | `null` | Optional MCP workspace restriction; access checks still apply. |
| `mcp.tools` | `null` | Optional tool allowlist; null uses the supported tool set subject to authorization. |
| `mcp.requests_per_minute` | `60` | MCP request rate limit. |
## Delivery

Examples and operational requirements: [read the guide](/cdn-delivery).

| Setting | Default | Behavior |
| --- | --- | --- |
| `delivery.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `delivery.edge_authorization_limit` | `6000` | Request rate limit per minute for the delivery/check endpoint, clamped to 1–60000. |
| `delivery.roles` | `[]` | Delivery-role definitions selecting supported delivery policies. |
| `delivery.profiles` | `[]` | Named delivery profiles referenced by consumers. |
| `delivery.disks` | `[]` | Per-disk CDN/delivery mappings. |
| `delivery.workspaces` | `[]` | Per-workspace delivery mappings. |
| `delivery.presets` | `[]` | Per-rendition delivery mappings. |
| `delivery.resolver` | `null` | Optional application delivery resolver implementation. |
| `delivery.adapters` | `[]` | Registered CDN delivery adapters. |
## Integrations

Examples and operational requirements: [read the guide](/browser-extension).

| Setting | Default | Behavior |
| --- | --- | --- |
| `integrations.browser_extension.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `integrations.browser_extension.path` | `"media-studio/extension/v1"` | Relative browser-extension API route prefix. |
| `integrations.browser_extension.studio_url` | `null` | Public Studio installation URL; MEDIA_STUDIO_EXTENSION_STUDIO_URL, falling back to APP_URL. |
| `integrations.browser_extension.token_days` | `30` | Extension connection token lifetime in days. |
| `integrations.spatie.mounts` | `[]` | Explicit optional Spatie Media Library source mounts. |
| `integrations.slack.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `integrations.slack.schedule` | `true` | Registers Slack maintenance for the enabled integration. |
| `integrations.slack.oauth.client_id` | `null` | Slack OAuth application client ID; MEDIA_STUDIO_SLACK_CLIENT_ID. |
| `integrations.slack.oauth.client_secret` | `null` | Slack OAuth secret; MEDIA_STUDIO_SLACK_CLIENT_SECRET. |
| `integrations.slack.oauth.redirect_uri` | `null` | Slack callback URI; MEDIA_STUDIO_SLACK_REDIRECT_URI. |
| `integrations.slack.oauth.middleware` | `["web","auth"]` | Middleware guarding Slack connection routes. |
| `integrations.slack.oauth.return_origins` | `[]` | Allowed origins for returning from the OAuth flow. |
| `integrations.slack.oauth.gov` | `false` | Selects Slack Gov endpoints when applicable. |
## Upload

Examples and operational requirements: [read the guide](/uploads).

| Setting | Default | Behavior |
| --- | --- | --- |
| `upload.receipts.lease_seconds` | `300` | Upload publication receipt lease duration in seconds. |
| `upload.receipts.cleanup_retry_seconds` | `3600` | Delay in seconds before retrying receipt cleanup. |
| `upload.max_kilobytes` | `25600` | Maximum file size for the ordinary upload path, in KiB; separate from resumable-session limits. |
| `upload.mime_types` | `null` | Optional MIME allowlist; null leaves the global type restriction unconfigured. Field/source policies can narrow it. |
| `upload.sessions.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `upload.sessions.chunk_bytes` | `5242880` | Default resumable chunk size in bytes. |
| `upload.sessions.max_bytes` | `10737418240` | Maximum bytes per resumable upload session. |
| `upload.sessions.max_parts` | `10000` | Maximum parts admitted per upload session. |
| `upload.sessions.ttl_seconds` | `86400` | Upload-session validity in seconds. |
| `upload.sessions.max_active` | `20` | Maximum active sessions admitted by the upload policy. |
| `upload.sessions.max_concurrent` | `3` | Maximum concurrent transfers advertised/enforced by the session policy. |
| `upload.sessions.max_reserved_bytes` | `53687091200` | Maximum bytes reserved by admitted sessions. |
| `upload.sessions.staging_directory` | `"{storage}/app/media-studio-upload-parts"` | Local staging path for server-proxied chunks; requires writable temporary capacity even with remote storage. |
| `upload.sessions.prune_batch` | `100` | Maximum expired sessions handled per pruning batch. |
| `upload.sessions.lock_seconds` | `300` | Upload-session lock lifetime in seconds. |
| `upload.sessions.multipart.enabled` | `false` | Allows direct private-S3 multipart where the client/source supports it; not every upload consumer requests direct mode. |
| `upload.sessions.multipart.threshold_bytes` | `104857600` | File-size threshold in bytes for selecting direct multipart. |
| `upload.sessions.multipart.part_bytes` | `8388608` | Multipart part size in bytes, subject to S3 requirements. |
| `upload.sessions.multipart.url_seconds` | `300` | Presigned part-upload URL validity in seconds. |
