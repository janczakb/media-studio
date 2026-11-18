---
title: "MCP permissions and credentials"
description: "Configure managed clients, host authentication, workspace scopes, origins and token rotation."
---

## Managed credentials

Create a dedicated principal with `studio.mcp.use`, membership in the required
workspaces and the [permissions for its tools](/mcp-tools). Use a different
connection ID for each independent client. Generate the secret locally.

```bash
php -r '$secret = bin2hex(random_bytes(32)); echo "Token: fms_mcp_catalog.".$secret.PHP_EOL."Hash: ".hash("sha256", $secret).PHP_EOL;'
```

Store the token in the client's secret store and only its hash in the Laravel
environment. Set an explicit expiry as a Unix timestamp in seconds.

```php
// config/media-studio.php, inside the returned array
'mcp' => [
    'enabled' => true,
    'authentication' => 'managed',
    'path' => 'media-studio/mcp',
    'origins' => ['https://media.example.com'],
    'connection_middleware' => [],
    'workspaces' => null,
    'tools' => null,
    'requests_per_minute' => 60,
    'connections' => [
        'catalog' => [
            'enabled' => true,
            'token_hash' => env('STUDIO_MCP_CATALOG_HASH'),
            'expires_at' => (int) env('STUDIO_MCP_CATALOG_EXPIRES_AT'),
            'principal_type' => 'user',
            'principal_id' => '42',
            'tenant_key' => null,
            'workspaces' => ['01J9GQ4ZPS9Z91FMY3JC5M8HJX', '01J9GQ4ZPS9Z91FMY3JC5M8HJY'],
            'tools' => ['studio_list_workspaces', 'studio_list_files', 'studio_get_file', 'studio_list_comments', 'studio_create_comment'],
            'workspace_tools' => [
                '01J9GQ4ZPS9Z91FMY3JC5M8HJY' => [
                    'studio_list_files', 'studio_get_file', 'studio_list_comments',
                ],
            ],
        ],
    ],
],
```

In this example the second workspace is read-only for this credential. Studio
accepts only explicitly named tools. An empty list grants none. `*` has no
special meaning. A global list further narrows connection grants. A
`workspace_tools` entry can only remove tools from that workspace. A
`null` workspace list means all workspaces accessible to the principal,
subject to global config and tenant policy. Workspace creation is a tenant-level
operation requiring its own grant.

The connection ID begins with a lowercase letter and may contain lowercase
letters, digits, underscores and hyphens (up to 64 characters). Its token
secret is 64 lowercase hexadecimal characters. `principal_type` is the
application's registered Studio model alias. `principal_id` is a string
identifier, including when the underlying database key is numeric.

## Tenant isolation

For a non-tenant installation, explicitly set `tenant_key => null`. In a
tenant installation use the corresponding `TenantIdentity::key()`. Configure
trusted `connection_middleware` to establish the tenant before Studio
authorizes the managed principal. Keep tenant resolution available for both
the MCP endpoint and its HTTP transfer paths. The resolved tenant must match
the credential and the workspace. See [tenancy](/tenancy).

## Host-authenticated mode

With `authentication => 'bearer'`, Studio relies on applications' configured
middleware and principal. The default is `middleware => ['auth:sanctum']`.
Only the four browse/read tools are offered in this mode. Its global workspace
and tool allowlists still apply. The local standalone demo may use a session
middleware configuration. The managed mode is required for mutation and
binary-transfer tools.

## Rotate or revoke

Replace `token_hash` and expiry under the existing ID to rotate without
changing the connection's upload ownership scope. The principal and tenant
must also stay the same to resume those sessions. A different connection ID
cannot resume the former client's uploads. Disable with `enabled => false`
or remove the connection. Reload cached configuration and long-lived workers
on all application instances when rotating or revoking a grant.

Revocation stops further MCP calls and authenticated transfers once applied.
Already-issued S3 part URLs remain usable until their signature expires.
They cannot complete an upload by themselves.

## Origin, rate limits and logs

- Serve the MCP endpoint and transfers over HTTPS. Clients send the bearer
  token in the Authorization header. Proxy and client logs should redact
  credentials, signed URLs and file content.
- If the request has an Origin header, it must match an allowed origin
  exactly. With `origins => null`, Studio uses the application URL without
  a trailing slash. Requests without Origin are accepted through the same
  authentication chain.
- `requests_per_minute` defaults to 60 and is clamped to 1–600 for the
  Laravel throttle middleware. Both RPC calls and authenticated transfer routes
  pass through the middleware.
- Studio logs managed mutation outcomes with tool, connection, principal and
  workspace identifiers when available. Transfer audit entries identify the
  uploaded entry. Configure retention and access to application logs.
- MCP web responses use private, no-store caching. See the
  [troubleshooting checklist](/mcp-troubleshooting) when using a reverse proxy.
