---
title: "Set up FLEX Fields and Studio"
description: "Verify optional integration support, serve matching assets and authorize the workspace."
---

Install compatible FLEX Fields and Studio packages in the host application. Check that the installed FLEX Fields build provides `FlexFileUpload::mediaStudio()`. This factory selects `UseFlex\MediaStudio\Integrations\FlexFields\StudioFileUpload`.

## Verify the installed packages

```bash
composer show janczakb/filament-flex-fields
composer show useflex/media-studio
```

Use your licensed package distribution or the application's configured Composer repositories. Apply [Studio installation](/installation), migrations and [Filament setup](/filament-setup) before adding the field.

## Prepare an authorized workspace

Resolve the workspace ID from the current server-side tenant or host record. For a create form, use a resolver that works before the record exists. The selected workspace must allow the current user to view the library. New uploads additionally require upload permission. See [permissions](/filament-permissions) and [tenant integration](/filament-tenancy).

## Serve the Studio assets

Serve the matching Studio JavaScript module and stylesheet and pass their URLs to `studio()`. FLEX's component assets use its asset manager. Studio's module and CSS are loaded when its picker mounts. Serve `rich-editor-extension.js` alongside the module when using the [rich editor integration](/filament-flex-rich-editor).

For example, this field allows choosing existing PDF files only.

```php
use Bjanczak\FilamentFlexFields\Filament\Forms\Components\FlexFileUpload;

FlexFileUpload::mediaStudio('documents')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        acceptedMimeTypes: ['application/pdf'],
    )
    ->studioUpload(false)
    ->multiple()
    ->maxFiles(5);
```

## Check the complete form workflow

Open Choose media, confirm the workspace is correct, select a permitted file and save the form. Reload and confirm its metadata is resolved. Then enable uploads in a test field, upload an allowed file and verify it appears once in Studio. The [persistence guide](/flex-fields/persistence) explains how to save references and protect used assets.
