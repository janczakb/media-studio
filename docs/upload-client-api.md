---
title: "Upload API for custom clients"
description: "Create, resume, finish and cancel upload sessions using the Studio API."
---

These endpoints support authenticated uploads. Choose the browser session, [REST token](/api/authentication), or [browser extension](/browser-extension) transport appropriate to your client.

## Use the upload API from your own client

Use `GET workspaces/{workspace}/upload-policy` to choose the transfer method.
For a resumable upload, send a file name, byte size and stable request `key` to
`POST workspaces/{workspace}/upload-sessions`. You can pass `direct: true` when
the client supports signed S3 uploads. The session response includes
`transport` (`local` or `s3-multipart`), `chunkBytes` and `acceptedParts`.

Part indexes start at zero in the Studio API. In a local session, send bytes to
`PUT .../parts/{part}`. In a direct session, call `POST .../parts/{part}/sign`
with the lowercase hex `sha256`, upload the exact part with the returned headers,
then send the quoted S3 ETag as `etag` to `POST .../parts/{part}/receipt`. Call
`POST .../complete` after all parts are accepted. Retrying completion of an
accepted session returns the same file.

## Request and state reference

Paths below are relative to the active API base. Add your usual JSON headers and authentication to Studio requests. The signed storage request uses only the headers from the signer.

| Method | Relative path | When to use it |
| --- | --- | --- |
| GET | `/workspaces/{workspace}/upload-policy` | Discover the workspace's allowed transfer settings. |
| POST | `/workspaces/{workspace}/upload-sessions` | Create or recover the session for an operation key. |
| GET | `/workspaces/{workspace}/upload-sessions/{session}` | Read accepted parts before continuing. |
| PUT | `…/{session}/parts/{part}` | Send raw bytes through Laravel. Optional `X-Part-SHA256` is lowercase hex. |
| POST | `…/{session}/parts/{part}/sign` | Request a signed direct upload URL. |
| POST | `…/{session}/parts/{part}/receipt` | Submit the ETag after a direct upload. |
| POST | `…/{session}/complete` | Verify, publish and return the file card. |
| DELETE | `…/{session}` | Cancel the session. |

A client that supports both transports can start with this JSON request. `parent` is an optional folder ULID. The file name, size and destination must remain consistent when retrying the same key.

```json
{
  "name": "campaign-footage.mp4",
  "size": 155189248,
  "mime": "video/mp4",
  "key": "capture_73a35f8a3f644738a2e9",
  "direct": true
}
```

Slice the source using the returned `chunkBytes`, send only parts that are absent from `acceptedParts`, then complete. After a lost receipt response, check status before retrying. After a lost completion response, repeat the completion with the same session. If the session expires, start a new operation and explicitly request the source file again if the client lost it.
