---
title: "Cache and shared services"
description: "Choose shared stores and understand cache invalidation in multi-server Studio deployments."
---

The choice of store matters when requests for one workspace can land on multiple web or worker processes.

## Cache

Studio can reuse configured filter catalogs across requests. Set `store` to the name of a Laravel cache store. For a Redis store, configure the Redis connection in your Laravel application first.

```php
return [
    // Other Studio settings...
    'cache' => [
        'store' => 'redis',
        'prefix' => 'media-studio',
        'catalog_ttl' => 300,
    ],
];
```

Set `store` to `null` to use the application's default store, or `catalog_ttl` to `0` to bypass catalog caching. Workspace authorization is checked on every request.
