# Storage and processing

Managed storage uses `storage.disk` and `storage.root`, recorded on workspace
creation. UI folders are database nodes; renaming/moving a folder must not rename
an S3 prefix of all descendants. The host's Laravel disk owns credentials,
endpoint/region and visibility. Keep originals private; content URLs are authorized
transport outputs, not permanent application references.

## Upload

Use `Library\UploadAsset` or the versioned HTTP/upload-session APIs rather than
writing a storage object and inserting an entry by hand. `UploadAsset::execute`
needs an authorized workspace, registered `Principal`, internal nullable integer
parent ID, `UploadedFile`, and idempotency key. Do not confuse public ULIDs from
routes with internal database IDs. Inspect its current signature before passing
owner/model or imported rendition options.

Native uploads, resumable server chunks and direct S3 multipart are separate
paths. `upload.sessions.enabled` and `.multipart.enabled` default false. A direct
consumer must explicitly request supported direct mode. Browser clipboard paste
uses the existing transfer pipeline and captures the currently open destination;
it does not bypass MIME/extension/size/folder policy.

## Derivatives

- Images: `processing.renditions`, native driver `auto|gd|imagick`, named thumbnail
  and preview roles. GD/Imagick codec support is required. Use current-version
  ready thumbnails, not the full original, for grid/list items.
- PDF: `processing.document_thumbnails.enabled` defaults false; Poppler first page
  or a PDF-enabled libvips backend. `execution:'command'` records pending work;
  run `media-studio:renditions:reconcile --documents --limit=25 --max-seconds=60`.
  Queue mode needs its document queue; sync is deliberately not dispatched.
- Office: explicit MIME registration with `OfficeThumbnailGenerator`, LibreOffice
  and an actually isolated worker. `office_isolated_worker` acknowledges isolation;
  it does not create a sandbox.
- Video: `processing.video.enabled`, `poster`, `compress` independently select
  FFmpeg still/MP4 outputs. Recipe admission requires an async processing connection.
  `video_editing` is a different feature and queue.
- External thumbnails: registered providers/Spatie/application sources own their
  conversions. Browsing a mount does not authorize native regeneration/deletion.

Configuration changes do not rewrite recorded recipes. Use bounded authorized
backfill/reconcile and inspect cursor/status rather than processing everything in
one request. Respect source bytes/pixels, decoder working memory, timeouts,
concurrency, retries and current-version checks. Queues and S3 still need local
temporary space for native processing.

## Lifecycle

File versions, generated renditions and usage references have separate retention.
Keep-history, original replacement and per-field replacement are explicit
policies. Staging/publication/cleanup is not one atomic storage+database transaction.
Do not delete an object while another version/rendition still owns a reference.
Trash revokes public shares; restore must not silently reactivate old tokens.
Trash expiry defaults off. Verify large-tree operations through the durable
operation state, not a browser-only spinner.
