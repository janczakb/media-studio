---
title: "Website attributes and extension API"
description: "Provide stable asset metadata on your pages and understand the scoped extension endpoints."
---

Site owners can add collection attributes to their HTML so the extension can find the intended image and its metadata. Installation administrators can use the extension-specific API to understand permissions and troubleshoot pairing or transfer behavior.

## Supply asset metadata

```html
<img
  src="/images/red-macaw-card.webp"
  data-flex-src="/images/red-macaw-original.jpg"
  data-flex-name="Red macaw portrait"
  data-flex-url="https://catalog.example.com/animals/red-macaw"
  data-flex-description="Reference image for the rainforest collection."
  data-flex-tags="animals,birds,rainforest"
  data-flex-width="2400"
  data-flex-height="3600"
  alt="A red macaw perched on a branch"
/>
```

The collector reads these attributes when inspecting eligible elements. Review the detected candidate and destination before saving.

| Attribute | Purpose |
| --- | --- |
| `data-flex-src` | Intended downloadable source, resolved relative to the page |
| `data-flex-name` | Suggested filename |
| `data-flex-url` | HTTP or HTTPS reference URL stored as source metadata |
| `data-flex-description` | Source description, bounded to 4,000 characters |
| `data-flex-tags` | Comma-separated labels, up to 32 |
| `data-flex-width` | Declared positive image width |
| `data-flex-height` | Declared positive image height |

Use attributes on the image or candidate element itself. Width and height assist candidate display and filtering. Studio still validates the downloaded file. Tags require the saving account's tag-management permission. Descriptions are capture metadata even though the save surface does not offer a general description input.

## Extension API

The default base is `/media-studio/extension/v1`. The discovery endpoint is `/.well-known/flex-media-studio.json`. The connection uses a dedicated browser token issued after approval. Use [REST authentication](/api/authentication) for a general application client.

| Method and relative path | Purpose |
| --- | --- |
| POST `/devices` | Begin a ten-minute approval request using a challenge |
| POST `/devices/claim` | Claim the approved request using the verifier |
| GET `/connection` | Read the approved workspace, expiry and current capabilities |
| DELETE `/connection` | Revoke the current connection |
| GET `/workspaces/{workspace}/folders` | Browse child folders with parent, search and directional cursors |
| POST `/workspaces/{workspace}/folders` | Create an authorized folder |
| GET `/workspaces/{workspace}/tags` | Query bounded tag suggestions |
| POST `/workspaces/{workspace}/notes` | Create a structured note |
| POST `/workspaces/{workspace}/uploads` | Upload a file with an operation key |
| POST `/workspaces/{workspace}/captures/{entry}` | Apply source metadata and tags to the connection's accepted upload |
| GET `/workspaces/{workspace}/upload-policy` | Read applicable upload limits |
| POST `/workspaces/{workspace}/upload-sessions` | Create a resumable upload session |

The remaining session status, part, completion and cancellation routes use the [upload session contract](/upload-client-api#request-and-state-reference) under this base. Parts are indexed from zero.

Folders return up to 60 items. Use `after` or `before` with the returned directional cursor, keeping the same parent and search. Search is a normalized name prefix. Tag suggestions are bounded to 15 choices per response.

## Authorization and storage

Every authorized extension request resolves the token's principal, tenant and workspace again. The requested workspace must match the connection. Feature-specific permissions still apply to creating notes, folders, uploads and tags. Current device requests are rate-limited to 240 per minute, with separate controls on pairing.

Uploaded files use the workspace's disk and Studio's validation, processing and encryption policies. Capture metadata is accepted only for an entry tied to the token's upload receipt or completed session. A connection cannot attach capture metadata to an arbitrary unrelated file.

The server stores a hash of the issued token secret. The browser stores its connection credential locally and uses it to authorize extension requests. Studio passwords are entered in the Studio sign-in flow.

## Verify a custom website

Collect a test page containing the image attributes above. Check its suggested name, dimensions and tags in the destination workflow. Save it into a disposable workspace folder and inspect its original, source metadata and tag assignments in Studio. Revoke the connection and confirm that subsequent protected requests are denied.
