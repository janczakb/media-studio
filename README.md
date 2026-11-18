<p align="center" class="filament-hidden">
  <picture>
    <source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/readme/logo-dark.svg">
    <img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/readme/logo-light.svg" alt="FLEX Media Studio" width="290" height="60">
  </picture>
</p>

<h1 align="center">FLEX Media Studio<br>Media library for Laravel &amp; Filament</h1>

<p align="center">
  <strong>Your media. Your infrastructure. One connected workflow.</strong>
</p>

<p align="center">
  Self-hosted digital asset management for your Laravel application and Filament panel.<br>
  Capture, organize and find images, videos and documents — then reuse them across forms and editors.<br>
  Connect your storage, collaborate with your team and add AI where it makes work easier.
</p>

<p align="center">
  <a href="https://github.com/sponsors/janczakb"><img src="https://img.shields.io/badge/GitHub-Sponsors-EA4AAA?style=flat-square&amp;logo=githubsponsors&amp;logoColor=white" alt="Sponsor Media Studio development"></a>
  <a href="https://github.com/janczakb/media-studio/stargazers"><img src="https://img.shields.io/github/stars/janczakb/media-studio.svg?style=flat-square&amp;color=EAB308&amp;label=GitHub%20stars" alt="GitHub stars"></a>
  <img src="https://img.shields.io/badge/PHP-8.3%2B-777BB4?style=flat-square" alt="PHP 8.3 or later">
  <img src="https://img.shields.io/badge/Laravel-12%20%7C%2013-FF2D20?style=flat-square" alt="Laravel 12 and 13">
  <img src="https://img.shields.io/badge/Filament-5%20%C2%B7%20optional-F97316?style=flat-square" alt="Optional Filament 5 integration">
  <img src="https://img.shields.io/badge/hosting-self--hosted-25CDF1?style=flat-square" alt="Self-hosted">
</p>

<p align="center">
  <a href="https://studio.bjanczak.com/"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/readme/documentation.svg?v=3" width="210" height="48" alt="Read the Media Studio documentation"></a>&nbsp;&nbsp;
  <a href="https://studio.bjanczak.com/filament"><picture><source media="(prefers-color-scheme: dark)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/readme/filament-dark.svg"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/readme/filament.svg" width="210" height="48" alt="Explore Filament components"></picture></a>
</p>

<p align="center">
  <a href="#why-choose-media-studio"><strong>Why Studio?</strong></a> &nbsp;·&nbsp;
  <a href="#media-library-features-at-a-glance"><strong>Feature catalog</strong></a> &nbsp;·&nbsp;
  <a href="#filament-components"><strong>Filament components</strong></a> &nbsp;·&nbsp;
  <a href="#getting-started"><strong>Getting started</strong></a>
</p>

<p align="center" class="filament-hidden">
  <a href="https://studio.bjanczak.com/">
    <img class="filament-hidden" src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/media-studio-product.webp?v=rounded-1" width="100%" alt="FLEX Media Studio: a shared media library, semantic search, contextual AI, private storage, Filament integration, browser capture, sharing and image editing">
  </a>
</p>

<p align="center">
  <sub>This repository is the public product overview. Package source and installable builds are not included here.</sub>
</p>

## Why choose Media Studio?

| What makes Studio worth choosing | What it gives your application |
| --- | --- |
| **Every asset. One library.** | Images, video, audio and documents in workspaces with folders, tags, favorites, comments and shared access. |
| **Find it. Search by meaning.** | Search names and tags, narrow results with filters, and enable content indexing, OCR and semantic retrieval when your installation needs them. |
| **Ask naturally. Act in context.** | An optional assistant that understands authorized library context and can find, tag and organize files through conversation. |
| **Your media. Your infrastructure.** | Self-hosted Laravel storage with local and S3-compatible disks, configurable CDN delivery, tenant isolation and role-based permissions. |
| **One asset. Reused across your app.** | Pick existing media in forms and editors using durable asset/version references, without uploading another copy just to select it. |
| **Found online. Saved to Studio.** | A browser extension for drag and drop, batch saving, screenshots, context-menu capture and bookmarks. |

Use Studio as a media workspace, embed its React interface in your application, or connect it to **Filament**. FLEX Fields is an optional integration; the library and native Filament components work independently of it.

## Find the file. Keep the momentum.

Search names and tags, narrow by file type, or describe what you need with optional semantic search. Use the same search panel for your library workflow.

<p align="center">
  <a href="https://studio.bjanczak.com/semantic-search"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/semantic-search.webp?v=rounded-1" width="100%" alt="Meaning-based Studio search with photo and document results, captured from the documentation homepage"></a>
</p>

## Describe the task. Let Studio help.

Find the right file without reproducing its exact name. The optional assistant works with authorized workspace context to rename, move, tag and discuss assets.

<p align="center">
  <a href="https://studio.bjanczak.com/assistant"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/ai-actions.webp?v=rounded-1" width="100%" alt="Studio AI actions showing a natural-language request and the matching library context"></a>
</p>

## A place for every file and every team

Keep reusable tags, folder navigation, visual feedback and workspace access together. Give each team a clear place to collect, review and reuse its media.

<table>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/tags"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/tags.webp?v=rounded-1" width="100%" alt="Reusable tags with search and suggestions"></a></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/folder-navigation"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/folders.webp?v=rounded-1" width="100%" alt="Lazy folder navigation and contextual selection"></a></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/image-preview-sdk"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/annotations.webp?v=rounded-1" width="100%" alt="Image annotations with arrows, shapes and text"></a></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/ai-image-labels"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/ai-tagging.webp?v=rounded-1" width="100%" alt="Optional AI tagging and image descriptions"></a></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/members"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/team-workspaces.webp?v=rounded-1" width="100%" alt="Team workspace members and access levels"></a></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/sharing"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/share-controls.webp?v=rounded-1" width="100%" alt="Share passwords, expiration and download controls"></a></td>
  </tr>
</table>

## Save inspiration while you browse

FLEX Capture connects Chrome and Firefox to your Studio workspace. Open the extension, drag an image, collect a batch, capture a region or use the context menu.

<p align="center">
  <a href="https://studio.bjanczak.com/browser-extension"><picture><source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/browser-menu-poster.webp?v=rounded-1"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/browser-menu.webp?v=rounded-1" width="100%" alt="Animated preview: opening the FLEX Capture menu over a wildlife photo collection"></picture></a>
</p>

<table>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/browser-extension/capture"><picture><source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/drag-drop-poster.webp?v=rounded-1"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/drag-drop.webp?v=rounded-1" width="100%" alt="Animated FLEX Capture Drag &amp; Drop demonstration"></picture></a><p><strong>Drag &amp; Drop</strong><br>Drag an image into the capture panel and choose its destination.</p></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/browser-extension/capture"><picture><source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/batch-save-poster.webp?v=rounded-1"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/batch-save.webp?v=rounded-1" width="100%" alt="Animated FLEX Capture Batch save demonstration"></picture></a><p><strong>Batch save</strong><br>Select the images you need, choose a folder and add tags before saving.</p></td>
  </tr>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/browser-extension/capture"><picture><source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/screenshot-poster.webp?v=rounded-1"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/screenshot.webp?v=rounded-1" width="100%" alt="Animated FLEX Capture Screenshot demonstration"></picture></a><p><strong>Screenshot</strong><br>Draw a selection around the region you want to capture.</p></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/browser-extension/capture"><picture><source media="(prefers-reduced-motion: reduce)" srcset="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/right-click-poster.webp?v=rounded-1"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/motion/right-click.webp?v=rounded-1" width="100%" alt="Animated FLEX Capture Right Click demonstration"></picture></a><p><strong>Right Click</strong><br>Save an image through its context menu, or use Alt + right-click.</p></td>
  </tr>
</table>

Animations demonstrate the capture workflow using sample media; they do not represent a live transfer from your account. Optional extension setup and workspace permissions still apply.

The extension connects to your installation through an approved, scoped connection. Its file intake uses Studio’s authorized upload workflow and server limits.

[Install FLEX Capture](https://studio.bjanczak.com/browser-extension/install) · [Explore capture workflows](https://studio.bjanczak.com/browser-extension/capture)

## From first upload to final delivery

Bring Studio into your Filament editor and prepare video clips without leaving the media workflow.

<table>
  <tr>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/filament-rich-editor"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/filament-editor.webp?v=rounded-1" width="100%" alt="Native Filament RichEditor with an image inserted from Studio"></a></td>
    <td width="50%" valign="top"><a href="https://studio.bjanczak.com/video-editing"><img src="https://raw.githubusercontent.com/janczakb/media-studio/main/art/sections/video-trimming.webp?v=rounded-1" width="100%" alt="Studio video editor with filmstrip, trim interval and save controls"></a></td>
  </tr>
</table>

<details>
<summary><strong>Explore the complete feature catalog</strong></summary>

## Media library features at a glance

From the first upload to the files reused in your application, Studio connects the everyday details of a media workflow.

| Area | What you can do |
| --- | --- |
| **Folders and navigation** | Browse nested folders with cursor pagination, a virtualized sidebar, contextual breadcrumbs and direct folder links. |
| **Tags and discovery** | Add or remove manual tags with live suggestions, filter by tag language, and combine file type, extension, uploader, date and size filters. |
| **Photos and favorites** | Review images and videos in a date-grouped timeline, adjust thumbnail density and aspect ratio, and revisit favorites or recently opened files. |
| **File information and notes** | Maintain alternative text and captions, create rich notes, and collect web bookmarks through the browser extension. |
| **File and folder uploads** | Add individual files or folder hierarchies, monitor progress, pause/resume supported transfers and retry failures. |
| **Large-file intake** | Enable resumable upload sessions or direct S3 multipart uploads with server-defined limits and short-lived signed parts. |
| **Preview and editing** | Inspect supported images, PDF and Office documents, play audio/video, annotate images and trim eligible videos. |
| **Image processing** | Define named thumbnail and preview conversions, including AVIF, and choose the ready preset used by supported picker/upload fields. |
| **Team review** | Invite members, assign workspace roles, comment on files, mention teammates and receive configured in-app or desktop notifications. |
| **Sharing and collection** | Protect links with passwords and expiry, control downloads, and optionally accept guest uploads into a shared folder. |
| **Share analytics** | Enable per-link visit and duration reporting, with country breakdowns when trusted location data is configured. |
| **Bulk work and archives** | Move, trash or restore selections, and offer authorized ZIP collection downloads or optional background archive preparation. |
| **Versions and Trash** | Publish selected image edits explicitly, configure replacement/version retention, restore deleted entries and enable scheduled Trash cleanup. |
| **Keyboard and clipboard** | Record your own Mac/Windows shortcut bindings and paste supported clipboard files into the open folder through the normal upload pipeline. |
| **Authentication and tenancy** | Reuse host authentication and tenant context, or configure optional standalone accounts with email verification and two-factor authentication. |
| **Language and presentation** | Use light/dark themes, English/Polish catalogs, application-provided translations, RTL support, branding and timezone preferences. |
| **Tenants and access** | Scope workspaces to tenants, reuse host authentication and enforce operation permissions with Spatie Laravel Permission 8. |
| **Existing libraries** | Index configured disk sources or connect Spatie Media Library models, collections and conversions. |
| **Private delivery and exports** | Use local or S3-compatible storage, configured CDN profiles and background archive downloads. |
| **Standalone or embedded** | Use your application's login, embed the React interface, or configure optional standalone accounts with email verification and two-factor authentication. |

Capabilities follow the installation's configuration, supported file formats and the current user's permissions. Optional services and processors are enabled separately.

[Library organization](https://studio.bjanczak.com/library-organization) · [Upload workflows](https://studio.bjanczak.com/uploads) · [Keyboard and clipboard](https://studio.bjanczak.com/keyboard-shortcuts) · [Languages and RTL](https://studio.bjanczak.com/localization)

</details>

## Filament components

Bring the same media library into fields, editor attachments, tables, record details and actions. Configure accepted types, selection counts, upload destinations and browse scope in PHP.

| Component or integration | Use it for |
| --- | --- |
| **[Studio Media Picker](https://studio.bjanczak.com/filament-media-picker)** | Select and reorder existing assets with list or grid previews, optional uploads and custom file actions. |
| **[Studio FileUpload](https://studio.bjanczak.com/filament-studio-file-upload)** | Connect native Filament uploads to a workspace and optionally choose existing Studio media. |
| **[Studio Media Column](https://studio.bjanczak.com/filament-media-column)** | Show authorized thumbnail previews in tables, with square, circular or stacked presentations. |
| **[Studio Media Entry](https://studio.bjanczak.com/filament-media-entry)** | Present saved media in infolists and record details. |
| **[RichEditor](https://studio.bjanczak.com/filament-rich-editor)** | Insert Studio images through the native editor, including configured ready image conversions. |
| **[MarkdownEditor](https://studio.bjanczak.com/filament-markdown-editor)** | Choose authorized images for Markdown content. |
| **[StudioPicker and actions](https://studio.bjanczak.com/filament-actions)** | Build an application-specific selection action around the Studio modal. |
| **[FLEX Fields](https://studio.bjanczak.com/flex-fields)** | Keep the FLEX upload and FlexRichEditor experience while connecting to Studio storage and references. |

<details>
<summary><strong>PHP examples: select existing images and upload to a fixed folder</strong></summary>

### Choose images from an approved library

This example assumes Studio and its matching browser assets are installed. Resolve `$workspaceId` and `$approvedImagesFolderId` from trusted application context.

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioMediaPicker;

StudioMediaPicker::make('gallery')
    ->studio(
        $workspaceId,
        '/studio-assets/studio.js',
        '/studio-assets/studio.css',
    )
    ->scopedFolder($approvedImagesFolderId)
    ->acceptedFileTypes(['image/jpeg', 'image/png', 'image/webp'])
    ->multiple()
    ->minFiles(1)
    ->maxFiles(8)
    ->layout('grid')
    ->layoutSwitcher(false)
    ->reorderable()
    ->allowUpload(false);
```

The field stores pinned media references. Cast the model attribute to `array` for a multiple field. Selecting an existing asset does not transfer its original bytes again. Configure [reference persistence and usage tracking](https://studio.bjanczak.com/filament-media-references) alongside your normal model-saving flow.

### Upload PDFs into a fixed destination

```php
use UseFlex\MediaStudio\Integrations\Filament\StudioFileUpload;

StudioFileUpload::make('document')
    ->studio(
        workspace: $workspaceId,
        moduleUrl: '/studio-assets/studio.js',
        stylesheetUrl: '/studio-assets/studio.css',
        uploadFolder: $documentsFolderId,
        acceptedMimeTypes: ['application/pdf'],
        allowLibrarySelection: true,
    )
    ->maxSize(10 * 1024)
    ->openable();
```

Workspace permissions, accepted file policies and server limits still apply. Selecting from the library reuses a reference; uploading a new file enters Studio's storage and processing pipeline. Removing a reference from a form does not delete a shared original.

[Filament setup](https://studio.bjanczak.com/filament-setup) · [All configuration options](https://studio.bjanczak.com/filament-configuration) · [Tenancy](https://studio.bjanczak.com/filament-tenancy) · [FLEX Fields integration](https://studio.bjanczak.com/flex-fields)

</details>

## AI that works with your library

Describe the asset or the task instead of reproducing an exact filename. Enable the capabilities your workflow needs; they have independent configuration, processing and access checks.

| Capability | What it adds |
| --- | --- |
| **[Contextual assistant](https://studio.bjanczak.com/assistant)** | Conversations with authorized file context and supported actions such as finding, tagging and organizing assets. |
| **[AI labels and descriptions](https://studio.bjanczak.com/ai-image-labels)** | Visual analysis with reviewable suggestions, separate from manually managed tags. |
| **[Video analysis](https://studio.bjanczak.com/ai/video-analysis)** | Analyze a bounded set of sampled video frames and retain timecoded results. |
| **[OCR](https://studio.bjanczak.com/ai/ocr)** | Text extraction from images and scanned PDF pages using the configured OCR service. |
| **[Semantic and hybrid search](https://studio.bjanczak.com/semantic-search)** | Meaning-based retrieval through Meilisearch's configured embedder. |
| **[AI image editing](https://studio.bjanczak.com/ai/image-editing)** | Generate candidates, review the results and explicitly choose which version to publish. |

Chat, visual analysis, OCR and embeddings can use different providers. Enabling one does not automatically scan the entire library or enable the others. External providers receive the inputs your installation authorizes and sends to them.

[AI setup](https://studio.bjanczak.com/ai/setup) · [Providers](https://studio.bjanczak.com/ai/providers) · [Limits and operations](https://studio.bjanczak.com/ai/operations)

## Storage, image conversions and private media delivery

Keep originals on a configured Laravel disk. Generate the image conversions your interface needs, deliver them through the configured storage or CDN, and reuse asset identity across the application.

| Your workflow | Studio's role |
| --- | --- |
| **Image delivery** | Named conversions with configurable dimensions, format, quality and metadata handling. Default native image presets use AVIF. |
| **Document previews** | Optional PDF and document thumbnail processing, plus supported Office and PDF previews in the browser. |
| **Video** | In-browser playback, trimming and optional background poster or playback generation. |
| **Existing libraries** | Optional Spatie Media Library integration and indexed disk sources, without moving originals simply to browse them. |
| **Private delivery** | Authorization-aware file access, configured S3 policies and optional CDN delivery profiles. |
| **Versions and cleanup** | Explicit version publication, configurable replacement policies, Trash and optional retention-based permanent cleanup. |

Conversions and specialized previews depend on enabled processors, supported formats and their runtime dependencies. Queued processing needs a running worker. S3 encryption policies are supported; the separate portable application-encryption driver remains experimental. Studio does not imply that every deployment is fully encrypted.

[Storage](https://studio.bjanczak.com/storage-s3) · [Image conversions](https://studio.bjanczak.com/image-conversions) · [Document thumbnails](https://studio.bjanczak.com/document-thumbnails) · [CDN delivery](https://studio.bjanczak.com/cdn-delivery) · [Encryption](https://studio.bjanczak.com/encryption)

## Connect your application and your AI tools

| Integration | Where to start |
| --- | --- |
| **React SDK** | [Embed Studio](https://studio.bjanczak.com/embedding) with your transport, theme, locale, branding and configured capabilities. |
| **REST API** | [API documentation](https://studio.bjanczak.com/api/introduction) for application integrations and authenticated media operations. |
| **MCP** | [Connect compatible AI clients](https://studio.bjanczak.com/mcp) with scoped tools, workspace access and operation permissions. |
| **CLI** | [Command-line workflows](https://studio.bjanczak.com/cli) for imports, planning, transfers and automation. |
| **Laravel Boost** | [Studio development guidance and skill](https://studio.bjanczak.com/boost) for coding agents working on integrations. |
| **Plugin center** | [Slack integration](https://studio.bjanczak.com/slack) and configurable application connections. |

The in-app assistant, external MCP access and Boost's development skill serve different purposes. Enable each independently. Neither a client connection nor a coding skill grants access to private media by itself.

## Getting started

**Start with the [installation guide](https://studio.bjanczak.com/installation)** and the distribution available to your application. This public overview repository does not contain the Composer package, React build, CLI archive or browser-extension builds.

The Laravel package declares **PHP 8.3+** and **Laravel 12 or 13**. The interface uses **React 19**. **Spatie Laravel Permission 8** is a core dependency. **Filament 5, FLEX Fields, Spatie Media Library, AI services, search and MCP** are optional integrations with their own dependencies and setup.

1. Install the matching packages and browser assets through your distribution.
2. Configure your authenticatable principal, private storage and workspace access.
3. Apply the relevant migrations and grant the required operation permissions.
4. Choose the standalone interface, embedded React interface or Filament integration.
5. Enable only the processors and integrations your application needs; start their workers as required.

[Installation](https://studio.bjanczak.com/installation) · [Configuration](https://studio.bjanczak.com/configuration) · [Filament setup](https://studio.bjanczak.com/filament-setup) · [Operations](https://studio.bjanczak.com/operations)

<details>
<summary><strong>Find the right integration for your application</strong></summary>

## Find the solution your application needs

| You are building… | Start with… |
| --- | --- |
| Product galleries and editorial media | [Studio Media Picker](https://studio.bjanczak.com/filament-media-picker) and [RichEditor](https://studio.bjanczak.com/filament-rich-editor). |
| An approved document library | [Folder-scoped selection](https://studio.bjanczak.com/filament-configuration), [PDF previews](https://studio.bjanczak.com/file-previews) and [sharing](https://studio.bjanczak.com/sharing). |
| A multi-company application | [Tenant isolation](https://studio.bjanczak.com/tenancy) and [Filament tenancy](https://studio.bjanczak.com/filament-tenancy). |
| A searchable visual archive | [AI labels](https://studio.bjanczak.com/ai-image-labels), [OCR](https://studio.bjanczak.com/ai/ocr) and [semantic search](https://studio.bjanczak.com/semantic-search). |
| An existing media collection | [Spatie Media Library](https://studio.bjanczak.com/spatie-media-library) or [indexed disk sources](https://studio.bjanczak.com/existing-disk-sources). |
| A team capture workflow | [Browser extension](https://studio.bjanczak.com/browser-extension), [members](https://studio.bjanczak.com/members) and [comments](https://studio.bjanczak.com/comments). |
| Automated imports and external tools | [CLI](https://studio.bjanczak.com/cli), [REST API](https://studio.bjanczak.com/api/introduction) and [MCP](https://studio.bjanczak.com/mcp). |

</details>

## Questions before you integrate

<details>
<summary><strong>Do I need Filament or FLEX Fields?</strong></summary>

No. The Laravel library and React interface are independent. Filament provides optional form, table, infolist and editor adapters. FLEX Fields adds an optional integration with its own upload and rich-editor components.

</details>

<details>
<summary><strong>Does choosing a Studio file upload it again?</strong></summary>

No. Library selection stores an asset/version reference. A new upload creates media through the upload pipeline. Reusing an existing file does not create a second binary copy just because another form selected it.

</details>

<details>
<summary><strong>Can I restrict a field to PDFs, images or a specific folder?</strong></summary>

Yes. Configure the supported adapter's MIME allowlist, browse scope, upload destination and minimum/maximum selection count. Client controls guide selection; the server rechecks access and the configured constraints when resolving the result.

</details>

<details>
<summary><strong>Does Studio require AI or a search service?</strong></summary>

No. The core library works without generative AI or Meilisearch. The assistant, visual analysis, OCR and semantic search are independently configured optional capabilities.

</details>

<details>
<summary><strong>Can I keep my existing Spatie Media Library?</strong></summary>

Yes. The optional adapter indexes existing media and works with your configured models, collections and conversions. It does not require copying every original into a new directory just to browse it.

</details>

<details>
<summary><strong>Do large folders load the entire library into the browser?</strong></summary>

Folder navigation and large lists use paginated requests and virtualized views. Breadcrumbs return a bounded context instead of requiring the full ancestry in every response. Your dataset, indexes, processors and deployment still determine actual performance.

</details>

<details>
<summary><strong>Where are the source code, downloads and license terms?</strong></summary>

This repository currently publishes the product overview and presentation assets only. Follow the documentation for integration guidance. No package release, download availability or software-license grant is implied by this README.

</details>

## Part of the FLEX family

| Product | What it brings |
| --- | --- |
| **[FLEX Fields](https://github.com/janczakb/filament-flex-fields)** | Advanced Filament form components, including uploads and rich editing that can connect to Media Studio. |
| **[FLEX Theme](https://github.com/janczakb/filament-flex-theme)** | A coordinated panel experience with visual theme configuration and light/dark presentation. |
| **FLEX Media Studio** | A shared, self-hosted media workspace for your Laravel application and its integrations. |

Each product has its own installation and configuration. Use the combination your application needs.

## Build your media workflow with Studio

Choose how your team captures, organizes and reuses files. Keep the library connected to the storage, forms and tools you use.

**[Explore the documentation](https://studio.bjanczak.com/)** · **[Filament components](https://studio.bjanczak.com/filament)** · **[AI capabilities](https://studio.bjanczak.com/ai)** · **[Browser extension](https://studio.bjanczak.com/browser-extension)**

If Studio fits your workflow, [star the repository](https://github.com/janczakb/media-studio/stargazers) to help other Laravel and Filament developers discover it.

## Support the FLEX family

Help sustain the development of Media Studio and the FLEX ecosystem through **[GitHub Sponsors](https://github.com/sponsors/janczakb)** or **[Buy Me a Coffee](https://buymeacoffee.com/janczakb)**.

[Report an issue](https://github.com/janczakb/media-studio/issues) · [Discussions](https://github.com/janczakb/media-studio/discussions) · [Changelog](CHANGELOG.md) · [Support](SUPPORT.md) · [Contributing](CONTRIBUTING.md) · [Code of conduct](CODE_OF_CONDUCT.md) · [Security](SECURITY.md) · [Credits](CREDITS.md)

© 2026 Bartłomiej Janczak.
