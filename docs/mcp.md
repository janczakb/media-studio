---
title: "Connect an AI assistant with MCP"
description: "Connect an AI client to an authorized FLEX Media Studio library."
---

FLEX Media Studio exposes an optional Streamable HTTP MCP endpoint through
Laravel MCP. Connect a client to browse and search media or give a managed
connection specific permissions for file transfer, comments and organization.

## Install and connect

1. Install Studio and its migrations in the Laravel application serving the
   library. Install the optional Laravel MCP package and register the Studio
   permissions for the application's guard.

   ```bash
   composer require laravel/mcp:^1.1
   php artisan migrate
   php artisan media-studio:permissions --guard=web
   ```

2. [Configure authentication and grant tools](/mcp-access). Set
   `media-studio.mcp.enabled` to `true` and verify the route with
   `php artisan route:list --path=media-studio/mcp`. Rebuild any route cache
   after changing the path, enablement or authentication mode.
3. Add a Streamable HTTP server to your client. Enter the Studio URL, such as
   `https://media.example.com/media-studio/mcp`, and set the
   `Authorization: Bearer <token>` header using the client's secret store.
   A static bearer credential requires client support for custom headers.
   OAuth-only clients require a separate authorization integration.
4. Start with `studio_list_workspaces` and `studio_list_files`. The server
   returns the data the client can see within its granted scopes.

The Studio navigation item requires `studio.ai.view`. The MCP principal needs
`studio.mcp.use`, the relevant operation permissions and workspace access.
MCP and the [REST API](/api/introduction) are enabled independently.

## Verify tool discovery

A typical MCP client handles protocol negotiation and tool discovery for you.
This diagnostic is equivalent to the tool discovery request exercised by the
package tests. Substitute a token and origin for your own installation.

```bash
curl --fail-with-body "${STUDIO_ORIGIN}/media-studio/mcp" \
  -H "Authorization: Bearer ${STUDIO_MCP_TOKEN}" \
  -H 'Content-Type: application/json' \
  -H 'Accept: application/json, text/event-stream' \
  --data '{"jsonrpc":"2.0","id":1,"method":"tools/list","params":{}}'
```

Inspect `result.tools` for available names and input schemas. In a real
client, follow its negotiated protocol and preserve any required session
headers. Tool discovery is scoped to the connection. Individual calls also
check permissions and the requested workspace.

## Next steps

- [Permissions and credentials](/mcp-access) covers host authentication, managed
  grants, workspace allowlists and token rotation.
- [Tool reference](/mcp-tools) lists inputs, permissions, results and limits.
- [File transfers](/mcp-transfers) covers binary upload, imported renditions,
  resumable uploads and downloads.
- [Troubleshooting](/mcp-troubleshooting) covers deployment and client errors.

Studio MCP exposes media operations. Sharing links, member administration,
permanent purge and running paid AI jobs are outside the tool catalog.
Treat filenames, metadata, comments and their contents as untrusted data.
