---
title: "Originals and edited versions"
description: "Choose how Studio keeps upload originals and previous versions of edited media."
---

Studio has separate policies for originals replaced by optimization and versions superseded by a saved edit. Review both policies before changing storage retention.

## Keep or remove the uploaded original

```php
'processing' => [
    'originals' => [
        'keep' => true, // default
        'replacement_preset' => 'large-v1',
        'grace_seconds' => 86400,
    ],
],
```

Set `keep` to `false` to retire native image originals after all conversions are
ready. Studio first publishes the selected optimized conversion as a NEW source
version, preserves its thumbnail/preview mappings and updates the filename and
MIME type. It never changes the bytes behind an existing version identifier.
Failed or missing conversions prevent retirement. Documents, videos and Spatie
or other externally owned originals are not deleted by this policy.

The original remains temporarily available during the configured grace period
(minimum one hour). Run the following command manually or through your scheduler:

```bash
# Inspect only.
php artisan media-studio:originals:prune WORKSPACE PRINCIPAL_TYPE PRINCIPAL_ID

# Delete expired originals after checking permissions and live references.
php artisan media-studio:originals:prune WORKSPACE PRINCIPAL_TYPE PRINCIPAL_ID --apply --limit=100
```

Cleanup refuses the current source, referenced objects and workspaces with active
archive jobs. Switching `keep` back to `true` stops subsequent original deletion;
it cannot restore files already deleted. The optimized master remains available
for viewing and downloading. The high-resolution original is no longer
recoverable after deletion, and future processing can only use the retained
optimized master. Existing uploads are not automatically bulk rewritten.

When defining a custom profile with original retirement, make sure
`replacement_preset` names a conversion that profile actually generates.

### AVIF compression effort

The three default conversions use `avif_effort => 3` with quality 40/40/50 for
thumbnail, medium and large. Set `'avif_effort' => 6` on any preset in
`processing.renditions` or `processing.model_renditions` when you want to
spend more encoding time on compression. Studio maps effort to encoder speed
as `9 - effort`. Previously scheduled conversions keep their saved recipes.
GD and ImageMagick use different encoder pipelines, so identical settings do not
promise byte-identical output to Sharp. ImageMagick normalizes to sRGB before
stripping profiles; GD does not provide equivalent ICC colour management.

## Keep or retire earlier edits

By default, Studio keeps history for a managed file after a new edit is explicitly saved. Set this option in `config/media-studio.php` to retire the previous version after each future edit.

```php
'edited_versions' => [
    'keep_history' => false,
],
```

The stable entry remains in the library. A previous version is eligible for cleanup after the replacement is committed and readable. Version-specific usages and shared objects can prevent removal. This policy applies to future edited replacements and uses a retirement queue in the database, separate from Trash.

The FLEX Fields integration can opt into immediate cleanup for a specific field using its PHP policy. Review [FLEX Fields image editing](/filament-flex-file-upload) for the field configuration and rendition options.

```bash
php artisan media-studio:edited-versions:maintain --help
php artisan schedule:list
```

Test the change with a disposable managed image. Save an edit, reload the host form, and verify the selected entry still opens and its thumbnail refreshes. Check any application references pinned to the earlier version before adopting the policy broadly.
