---
title: "Core configuration reference"
description: "Every shipped setting in this area, its default, purpose and related implementation guide."
---

Defaults below are from the package configuration with environment variables unset. Paths are relative to `media-studio`: for example, `storage.disk` means `config('media-studio.storage.disk')`. Empty arrays are extension points, not automatically discovered registrations.

See the [configuration guide](/configuration) for deployment steps and the [coverage and feature matrix](/configuration-reference) for scope.

## Edited Versions

Examples and operational requirements: [read the guide](/media-versions).

| Setting | Default | Behavior |
| --- | --- | --- |
| `edited_versions.keep_history` | `true` | Keeps superseded edited versions. False requests replacement retention; shared objects remain subject to ownership/reference checks. See media versions for per-field policy. |
## Navigation

Examples and operational requirements: [read the guide](/folder-navigation).

| Setting | Default | Behavior |
| --- | --- | --- |
| `navigation.verification_nodes` | `20000` | Maximum nodes examined by a navigation verification operation. |
| `navigation.verification_ms` | `2000` | Verification time budget in milliseconds. |
| `navigation.journal_days` | `7` | Navigation journal retention in days. |
| `navigation.publisher` | `null` | Optional navigation event publisher implementation; null leaves the integration unconfigured. |
## Encryption

Examples and operational requirements: [read the guide](/encryption).

| Setting | Default | Behavior |
| --- | --- | --- |
| `encryption.mode` | `"off"` | Policy: off, application, infrastructure or hybrid. Off does not disable encryption owned by the host/provider. |
| `encryption.experimental_file_driver` | `false` | Explicit gate for the experimental application file driver; leave disabled unless accepting that implementation's current limitations. |
| `encryption.database.enabled` | `false` | Enables supported database-field encryption, independently of disk encryption. |
| `encryption.database.allow_plaintext_reads` | `false` | Allows reading legacy plaintext during a deliberate migration; disable after migration. |
| `encryption.active_key` | `"v1"` | Key identifier used for new encrypted content. |
| `encryption.keys.v1` | `null` | Versioned application key from FMS_CONTENT_KEY_V1. Keep secret; retain old keys while encrypted data requires them. |
| `encryption.disks` | `[]` | Map of Laravel disk names to provider-default, sse-s3, sse-kms, dsse-kms or encrypted-volume policies. |
## Auth

Examples and operational requirements: [read the guide](/authentication).

| Setting | Default | Behavior |
| --- | --- | --- |
| `auth.enabled` | `false` | Registers Studio's standalone account flow; host authentication remains available separately. |
| `auth.registration` | `false` | Allows standalone account registration when standalone authentication is enabled. |
| `auth.prefix` | `"studio/auth"` | Relative path prefix for standalone account routes. |
| `auth.home` | `"studio"` | Destination path after standalone authentication. |
## Mail

Examples and operational requirements: [read the guide](/standalone-accounts).

| Setting | Default | Behavior |
| --- | --- | --- |
| `mail.mode` | `"inherit"` | inherit uses host mail configuration; mailer selects a named Laravel mailer; smtp builds a separate Studio transport. |
| `mail.mailer` | `null` | Optional named Laravel mailer for Studio messages. |
| `mail.from.address` | `null` | Studio sender address; FMS_MAIL_FROM_ADDRESS, unset by default. |
| `mail.from.name` | `"Media Studio"` | Studio sender display name; FMS_MAIL_FROM_NAME. |
| `mail.smtp.host` | `null` | Separate SMTP server; FMS_SMTP_HOST. |
| `mail.smtp.port` | `587` | SMTP port; FMS_SMTP_PORT, default 587. |
| `mail.smtp.scheme` | `"smtp"` | SMTP transport scheme; FMS_SMTP_SCHEME, default smtp. |
| `mail.smtp.username` | `null` | SMTP username; FMS_SMTP_USERNAME. |
| `mail.smtp.password` | `null` | SMTP password; FMS_SMTP_PASSWORD. Supply through deployment secrets. |
## Appearance

Examples and operational requirements: [read the guide](/embedding).

| Setting | Default | Behavior |
| --- | --- | --- |
| `appearance.primary_color` | `"#0866ff"` | Default Studio interface accent; instance branding can override presentation. |
## Routes

Examples and operational requirements: [read the guide](/embedding).

| Setting | Default | Behavior |
| --- | --- | --- |
| `routes.enabled` | `true` | Registers Studio's application API routes. REST, MCP and extension APIs have separate switches. |
| `routes.prefix` | `"media-studio/api/v1"` | Relative Studio application API prefix. |
| `routes.middleware` | `["web","auth"]` | Host middleware protecting application API routes. |
| `routes.base_url_resolver` | `null` | Optional base-URL resolver for embedding and host routing. |
## Workspace Creation

Examples and operational requirements: [read the guide](/workspace-preferences).

| Setting | Default | Behavior |
| --- | --- | --- |
| `workspace_creation` | `false` | Allows supported workspace creation flows; authorization and maximum-owned rules still apply. |
## Workspaces

Examples and operational requirements: [read the guide](/workspace-preferences).

| Setting | Default | Behavior |
| --- | --- | --- |
| `workspaces.enabled` | `true` | Enables the multi-workspace interface; disabling it does not remove tenant isolation or the default library. |
| `workspaces.max_owned` | `10` | Maximum workspaces one owner can create. |
| `workspaces.operations.connection` | `null` | Laravel queue connection; null inherits the application default. |
| `workspaces.operations.queue` | `"media"` | Queue name consumed by the worker for this subsystem. |
## Default Workspace

Examples and operational requirements: [read the guide](/workspaces).

| Setting | Default | Behavior |
| --- | --- | --- |
| `default_workspace.enabled` | `true` | Enables the default media library workflow. |
| `default_workspace.auto_join` | `true` | Automatically joins eligible principals to the default library. |
| `default_workspace.name` | `"Media library"` | Default library display name. |
## Sources

Examples and operational requirements: [read the guide](/existing-disk-sources).

| Setting | Default | Behavior |
| --- | --- | --- |
| `sources.mounts` | `[]` | Explicit indexed existing-disk source mounts; originals remain owned by their source. |
| `sources.sync.targets` | `[]` | Registered synchronization target definitions; source synchronization is explicit and incremental. |
## Permissions

Examples and operational requirements: [read the guide](/members).

| Setting | Default | Behavior |
| --- | --- | --- |
| `permissions.abilities` | `[]` | Overrides/mappings for Studio permission abilities; see member permission integration. |
| `permissions.labels` | `[]` | Human-readable permission label overrides. |
| `permissions.actor_resolver` | `null` | Application resolver translating the current host identity to the permission actor. |
| `permissions.editors.guardian` | `true` | Optional Guardian editor integration; does not install Guardian or bypass authorization. |
| `permissions.editors.shield` | `true` | Optional Shield editor integration; does not install Shield or bypass authorization. |
## Sharing

Examples and operational requirements: [read the guide](/sharing).

| Setting | Default | Behavior |
| --- | --- | --- |
| `sharing.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `sharing.trusted_country_header` | `null` | Country header trusted from your controlled proxy only; null disables this source. |
| `sharing.max_items` | `120` | Maximum entries admitted to a shared selection. |
| `sharing.max_links` | `1000` | Configured share-link limit. |
| `sharing.password_attempts` | `5` | Password attempt limit for protected shares. |
| `sharing.guest_upload_enabled` | `false` | Allows guest-upload links when sharing and applicable permissions permit them. |
| `sharing.guest_upload_max_kilobytes` | `25600` | Maximum guest-upload file size in KiB. |
| `sharing.guest_upload_max_total_bytes` | `1073741824` | Maximum total guest-upload bytes for the link policy. |
| `sharing.guest_upload_max_files` | `1000` | Maximum files accepted by the guest-upload policy. |
## Downloads

Examples and operational requirements: [read the guide](/archive-downloads).

| Setting | Default | Behavior |
| --- | --- | --- |
| `downloads.max_archive_entries` | `10000` | Maximum entries in an ordinary archive operation. |
| `downloads.background_enabled` | `false` | Enables durable background archive creation. |
| `downloads.background_disk` | `"local"` | Laravel disk holding generated background archives. |
| `downloads.background_max_entries` | `50000` | Maximum entries admitted to a background archive. |
| `downloads.background_ttl_hours` | `24` | Background archive lifetime in hours. |
| `downloads.queue` | `null` | Queue name consumed by the worker for this subsystem. |
| `downloads.connection` | `null` | Laravel queue connection; null inherits the application default. |
## Activity

Examples and operational requirements: [read the guide](/library-organization).

| Setting | Default | Behavior |
| --- | --- | --- |
| `activity.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
## Comments

Examples and operational requirements: [read the guide](/comments).

| Setting | Default | Behavior |
| --- | --- | --- |
| `comments.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
## Notifications

Examples and operational requirements: [read the guide](/notifications).

| Setting | Default | Behavior |
| --- | --- | --- |
| `notifications.enabled` | `true` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `notifications.push.enabled` | `false` | Enables this subsystem; additional provider, permission and deployment requirements still apply. |
| `notifications.push.public_key` | `null` | VAPID public key; FMS_WEB_PUSH_PUBLIC_KEY. |
| `notifications.push.private_key` | `null` | VAPID private key; FMS_WEB_PUSH_PRIVATE_KEY. Store as a deployment secret. |
| `notifications.push.subject` | `null` | VAPID contact subject; FMS_WEB_PUSH_SUBJECT. |
| `notifications.push.schedule` | `true` | Registers scheduled delivery for enabled web push. |
## Cache

Examples and operational requirements: [read the guide](/cache).

| Setting | Default | Behavior |
| --- | --- | --- |
| `cache.store` | `null` | Laravel store for Studio's catalog cache; null inherits the application default. |
| `cache.prefix` | `"fms"` | Cache-key namespace; retain isolation between installations. |
| `cache.catalog_ttl` | `300` | Catalog cache lifetime in seconds. |
## Trash

Examples and operational requirements: [read the guide](/trash).

| Setting | Default | Behavior |
| --- | --- | --- |
| `trash.retention_enabled` | `false` | Enables permanent removal of expired trash entries; disabled by default. |
| `trash.days` | `30` | Trash retention period in days when retention is enabled. |
| `trash.schedule` | `true` | Registers scheduled trash maintenance; does not enable retention by itself. |
| `trash.tree_batch_size` | `200` | Maximum tree entries processed per deletion batch. |
| `trash.max_objects_per_entry` | `1000` | Maximum storage objects admitted for one entry cleanup operation. |
| `trash.max_attempts` | `12` | Maximum processing attempts before work remains failed for explicit intervention. |
| `trash.queue` | `null` | Queue name consumed by the worker for this subsystem. |
| `trash.connection` | `null` | Laravel queue connection; null inherits the application default. |
## Browse

Examples and operational requirements: [read the guide](/custom-library-filters).

| Setting | Default | Behavior |
| --- | --- | --- |
| `browse.revision` | `1` | Browse recipe revision used to separate cached custom filter/sorter behavior. |
| `browse.filters` | `[]` | Application-defined server filter registrations. |
| `browse.sorters` | `[]` | Application-defined server sorter registrations. |
| `browse.tag_locales` | `["en","pl"]` | Allowed tag-language locales; does not translate tag text automatically. |
## Principals

Examples and operational requirements: [read the guide](/authentication).

| Setting | Default | Behavior |
| --- | --- | --- |
| `principals` | `[]` | Registered principal type-to-model mappings; supports host identity models. |
## Members

Examples and operational requirements: [read the guide](/members).

| Setting | Default | Behavior |
| --- | --- | --- |
| `members.directory` | `null` | Optional member-directory implementation used for authorized user lookup. |
| `members.search_attribute` | `"name"` | Host model attribute used for member search. |
| `members.email_attribute` | `"email"` | Host model attribute used for email lookup/invitations. |
| `members.invitation_days` | `7` | Invitation validity period in days. |
| `members.accept_redirect` | `"/"` | Destination after accepting an invitation. |
## Member Profiles

Examples and operational requirements: [read the guide](/members).

| Setting | Default | Behavior |
| --- | --- | --- |
| `member_profiles.resolver` | `"UseFlex\\MediaStudio\\Identity\\AttributeMemberProfileResolver"` | MemberProfileResolver implementation; default maps model attributes. |
| `member_profiles.fields.name` | `"name"` | Host model attribute mapped to member display name. |
| `member_profiles.fields.email` | `"email"` | Host model attribute mapped to member email. |
| `member_profiles.fields.avatar_url` | `"avatar_url"` | Host model attribute mapped to avatar URL. |
## Tenancy

Examples and operational requirements: [read the guide](/tenancy).

| Setting | Default | Behavior |
| --- | --- | --- |
| `tenancy.resolver` | `null` | Application tenant resolver; configure explicitly for multi-tenant hosts. |
| `tenancy.models` | `[]` | Allowed tenant model mappings. |
## Usage Owners

Examples and operational requirements: [read the guide](/media-references).

| Setting | Default | Behavior |
| --- | --- | --- |
| `usage_owners` | `[]` | Application owner model registrations for durable media references. |
## Storage

Examples and operational requirements: [read the guide](/storage-local).

| Setting | Default | Behavior |
| --- | --- | --- |
| `storage.disk` | `"local"` | Default Laravel disk for Studio-managed originals and derived objects. |
| `storage.root` | `"flex-media-studio"` | Storage-key namespace on the configured disk; independent of user folder paths. |
