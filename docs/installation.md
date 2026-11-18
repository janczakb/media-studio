---
title: Install Media Studio
description: Set up the Laravel package, migrations, storage, and a browser or Filament interface.
---

Media Studio can use an existing Laravel login, a Filament panel, or its own [standalone accounts](/standalone-accounts). Choose the identity mode before creating a workspace. The same Laravel library powers each interface.

## License scope

Personal noncommercial use and evaluation are free. For business production, [purchase a license](https://shop.bjanczak.com/checkout/buy/b087c01b-c85e-415a-af05-90690053c7d3) and review [plans and terms](/licensing).

## Add the package

The public source-available distribution contains the Laravel integration, Media Core and prebuilt browser assets. Install it from the public Composer registry after the Packagist listing has been submitted:

```bash
composer require useflex/media-studio:^0.1.0
php artisan vendor:publish --tag=media-studio-assets --force
php artisan vendor:publish --tag=media-studio-config
php artisan vendor:publish --tag=media-studio-migrations
php artisan migrate
```

No license key, private Composer repository or runtime activation is required to download or install the public package. A commercial license grants the legal right to business production use.

If Packagist has not indexed the package yet, register `https://github.com/janczakb/media-studio` as a VCS repository in your application, then run the same require command.

Check the package's Composer constraints against your host PHP and Laravel versions. Register the authenticatable classes that represent the users of Studio. Use a [private local disk](/storage-local) or [private S3 disk](/storage-s3) for managed originals.

```php
// In config/media-studio.php, keep the other published settings.
'principals' => [
    'user' => App\Models\User::class,
],
'storage' => [
    'disk' => 'local',
    'root' => 'flex-media-studio',
],
```

## Pick an interface

| Interface | Follow this guide |
| --- | --- |
| Laravel app with React or an embedded picker | [Configure and mount Studio](/configuration#branding-and-embedding) |
| Filament panel and editor attachments | [Filament integration](/filament) |
| Dedicated Studio login and email | [Standalone accounts](/standalone-accounts) |
| Headless process | [REST authentication](/api/authentication) or [MCP](/mcp) |

## Verify the installation

1. Sign in as a user registered in `principals` and open the host's Studio route.
2. Open the default library. Create a test folder and upload a small image.
3. Reload the library, preview the image and confirm the expected access for a second account.
4. If originals upload but thumbnails stay pending, verify the named [queue and processor settings](/operations).

```bash
php artisan route:list --path=media-studio
php artisan migrate:status
php artisan schedule:list
```

For custom URLs, use the resolved routes from the host application. If a configuration edit appears to have no effect, rebuild the host's configuration cache as part of its normal deployment.
