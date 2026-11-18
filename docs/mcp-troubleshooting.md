---
title: "Troubleshoot MCP"
description: "Diagnose connection, permissions, revisions, cursor and transfer failures."
---

## Check the connection

| Symptom | Check |
| --- | --- |
| Endpoint is absent | Check `laravel/mcp`, `mcp.enabled`, the configured path and route cache. Use `php artisan route:list --path=media-studio/mcp`. |
| HTTP 401 | Check the Authorization header, token format, hash, enabled state and expiry. In host-authenticated mode check the configured middleware and guard. |
| HTTP 403 | Check `studio.mcp.use`, principal registration, tenant match and the exact Origin header. |
| HTTP 429 | Respect the response's retry instructions. Reduce concurrent JSON-RPC and binary calls under the configured Laravel throttle. |
| Unexpected redirect or HTML response | Check the HTTP endpoint, HTTPS and proxy routing. The client should connect to the MCP route, rather than a login page or the Studio frontend. |
| Tool missing from discovery | Check authentication mode, connection and global tool lists, and feature flags. Search, comments and S3 Multipart have independent gates. |
| Tool listed but denied for a workspace | Check workspace membership, operation permissions, source policy, the workspace allowlists and `workspace_tools` for that workspace. |

On a reverse proxy, forward the Authorization, Accept, Content-Type and any
negotiated MCP headers. Keep the endpoint and its transfer subpaths on the
same HTTPS origin. Configure request size and timeout limits for the intended
binary uploads. Preserve provider transfer headers for direct S3 PUT requests.
Avoid caching MCP or rewriting a binary response as JSON.

## Diagnose an individual tool call

An HTTP 200 can carry a tool error. Check top-level JSON-RPC `error` and
`result.isError` before reading a successful payload.

| Managed tool error | Action |
| --- | --- |
| `access_denied` | Verify the credential and authorization scope for the exact operation. Missing and inaccessible resources can produce the same error. |
| `conflict` | Fetch the latest entry, comment or upload session. Review its new revision or status before retrying. |
| `invalid_arguments` | Compare with the reported tool schema and current workspace policy. Check required fields, JSON types, ULIDs and limits. |
| `expired` | Reopen the operation or file selection to obtain current state. |
| `rate_limited` | Back off and retry with bounded concurrency. |
| `operation_failed` | Check the Laravel logs and the service used by the operation. For uncertain writes first re-read the current resource or session. |

The four browse tools return simpler messages for invalid arguments, access
failures and unexpected errors. For paging errors, restart from the first
page. Cursors belong to a particular query and access scope.

## Diagnose a transfer

- On a size or MIME error, call `studio_get_upload_policy` again. Check the
  workspace settings, PHP upload limits and the reverse proxy request limit.
- If local chunks fail validation, compare the byte size, index and SHA-256
  of the exact part. Resume from `acceptedParts`.
- For S3, send the signed headers to the provider, preserve each quoted ETag
  and record it with Studio. A successful PUT alone does not complete the
  upload. Ask for a fresh signature if it has expired.
- If a download URL is rejected, prepare it again from an accessible file
  and version. The download route performs a fresh permission check.
- After token rotation reload config on every application instance. Resume
  with the same connection identity or start a new session.

See [file transfers](/mcp-transfers) and
[credential lifecycle](/mcp-access#rotate-or-revoke).
