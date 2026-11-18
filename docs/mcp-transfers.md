---
title: "MCP file transfers"
description: "Upload, import, resume and download file bytes using Studio's managed MCP connection."
---

Use a [managed connection](/mcp-access). Tool arguments carry metadata. The
client transfers binary data with HTTP and must be able to read the source file.
Resolve the relative upload, part and download paths against the trusted Studio
origin. Attach the MCP bearer credential only to that origin. S3 signed URLs
are sent to the configured S3 origin using their returned headers.

## Identify the effective limits

Call `studio_get_upload_policy` with `workspace`. It returns `accept`
(allowed file extensions), `ordinaryMaxBytes`, whether resumable uploads are
`enabled`, and the `chunkBytes`, `maxBytes`, `maxParts`, `maxConcurrent`
and `multipartThresholdBytes` values. Workspace and server restrictions
can change. Treat the returned limits as specific to that workspace.

## Upload a single file

Call `studio_prepare_file_upload` with `workspace`, then POST multipart
form data to its `uploadPath`. Send the file in `file` and optionally
send the destination folder ULID in `parent`. Omit `parent` for the root.

```bash
curl --fail-with-body "${STUDIO_ORIGIN}${UPLOAD_PATH}" \
  -H "Authorization: Bearer ${STUDIO_MCP_TOKEN}" \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: catalog-image-upload-001' \
  -F 'file=@./chair.png'
```

The key uses 16–128 letters, digits, underscores or hyphens on this HTTP
route. Keep the same key and identical input for a retry. A successful
upload returns HTTP 201 and an entry with `id`, `versionId` and `revision`.

## Import an image with supplied renditions

Call `studio_prepare_image_import` with `workspace`. Use its separate
`uploadPath` with the original in `file` and 1–12 named images under
`variants`. An optional `roles` map assigns the thumbnail or preview to
a variant from this request.

```bash
curl --fail-with-body "${STUDIO_ORIGIN}${UPLOAD_PATH}" \
  -H "Authorization: Bearer ${STUDIO_MCP_TOKEN}" \
  -H 'Accept: application/json' \
  -H 'Idempotency-Key: catalog-image-bundle-001' \
  -F 'file=@./chair.png' \
  -F 'variants[card]=@./chair-card.webp' \
  -F 'variants[preview]=@./chair-preview.webp' \
  -F 'roles[thumbnail]=card' \
  -F 'roles[preview]=preview'
```

Input names match `[a-z][a-z0-9_-]{0,22}`. The saved presets acquire the
`import-` prefix. Each image must meet workspace file policy and the
40-million-pixel validation limit. The complete bundle must fit the ordinary
upload byte limit. The source and supplied conversions belong to one version.

## Resumable uploads

1. Call `studio_begin_upload` with `workspace`, the filename in `name`,
   declared byte `size`, and retry `key`. Optionally provide `parent` and
   `direct: true` to prefer S3 Multipart.
2. Record the returned session `id`, `transport`, `chunkBytes`, `size`
   and `acceptedParts`. The card also returns the state and either the published
   entry or `entry: null`. It adds `partPathPrefix` for local transport.
3. Split the original into `chunkBytes` chunks starting at index 0. The last
   part contains the remainder. Hash the exact bytes of each part with SHA-256.
   Respect `maxConcurrent`. If the connection drops, query `studio_get_upload`
   with the same credential and resume the missing indices.
4. Call `studio_complete_upload` with `workspace` and `session` after all
   parts are accepted. A successful completion returns the published entry.
   Use `studio_cancel_upload` for an unfinished session that should be discarded.

### Local chunks

Send a binary PUT to `partPathPrefix` plus the zero-based part index. Include
`X-Part-SHA256` as 64 lowercase hex characters. The HTTP result is
`{"accepted":true}`. An accepted index can be retried with the same bytes.

```bash
curl --fail-with-body -X PUT "${STUDIO_ORIGIN}${PART_PATH_PREFIX}0" \
  -H "Authorization: Bearer ${STUDIO_MCP_TOKEN}" \
  -H "X-Part-SHA256: ${PART_SHA256}" \
  -H 'Content-Type: application/octet-stream' \
  --data-binary @./part-0.bin
```

### S3 Multipart

Use the transport returned by `studio_begin_upload`. `direct: true` is a
preference, and Studio may return local transport. For Multipart, call
`studio_sign_upload_part` with `workspace`, `session`, `part` and
`sha256`. PUT the exact bytes to its signed URL using the returned headers.
Keep the S3 `ETag`, including its quotes, and call `studio_record_upload_part`
with `workspace`, `session`, `part` and `etag`. A signed part only appears in
`acceptedParts` after recording. If a URL expires, ask for a new signature for
the same index and checksum. Never forward the Studio bearer token to S3.

## Download an authorized version

Call `studio_prepare_file_download` with `workspace`, `entry` and
optionally `version`. If you omit the version, Studio resolves the current
version when preparing the link. GET the returned `downloadPath` on the Studio
origin with the bearer token. The binary route checks workspace, permission
and version again. Set the output to a local path you control.

```bash
curl --fail-with-body "${STUDIO_ORIGIN}${DOWNLOAD_PATH}" \
  -H "Authorization: Bearer ${STUDIO_MCP_TOKEN}" \
  --output ./downloaded-file
```

See [upload and storage settings](/uploads) for limits, S3 setup and retention.
