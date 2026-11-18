---
title: "Install and update the Studio skill"
description: "Discover the package's Boost resources and activate them for your coding agent."
---

Use a Laravel Boost release supporting third-party package skills under
`resources/boost/skills`. Check the installed version and commands rather than
assuming the host uses the same release as your development environment.

## Add Boost to the development application

If Boost is not already installed:

```bash
composer require laravel/boost --dev
php artisan boost:install
```

Select your supported coding agent, guidelines and skills. When third-party
packages are offered, include **useflex/media-studio (guidelines, skills)**.
Boost discovers third-party resources from direct dependencies; if Studio is
installed only transitively, declare the actual Studio package as a direct host
dependency through your configured distribution before expecting discovery.

The guideline is short, while the development skill loads its detailed references
when relevant. This keeps unrelated prompts from carrying the entire Studio
manual.

## Existing Boost installation

```bash
php artisan boost:update
php artisan boost:list-skills
```

The update can offer newly discovered package resources. Include Media Studio and
verify `media-studio-development` appears with source `useflex/media-studio`.
Your Boost version may expose additional options; inspect `php artisan help
boost:update` before using automation. Do not use `--no-discover` when trying to
include a newly installed package for the first time.

Boost writes skills to the selected agent's supported location and manages its
own installation configuration. The package does not silently rewrite your
`AGENTS.md`, copy files on web requests or install anything into an AI account.
Restart/reload the coding agent's project context if it does not refresh skill
discovery automatically.

## Diagnose missing context

| Symptom | Check |
| --- | --- |
| Package is not offered | Installed Boost supports third-party skills, Studio is a direct dependency, and the distribution includes resources/boost |
| Skill missing from list | Third-party package selection, skill exclusions and successful Boost discovery |
| Agent ignores an installed skill | Ask it explicitly to activate media-studio-development and reopen the project context |
| Wrong method in generated code | Ask for installed source/signature verification; documentation for another version is not the authority |
| Instructions disappeared after update | Re-run Boost's supported update flow and preserve application-owned overrides separately |

For a package installed in a custom vendor directory, resolve its path with
Composer rather than hardcoding `vendor/useflex/media-studio`:

```php
$studioPath = \Composer\InstalledVersions::getInstallPath('useflex/media-studio');
```

Updating development guidance does not require enabling any Studio AI provider,
REST API, external MCP server or browser-extension scope.
