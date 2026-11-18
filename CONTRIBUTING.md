# Contributing

Help improve Media Studio through actionable bug reports, documentation corrections and proposals grounded in a media workflow.

Before opening a pull request, check existing issues and read the [documentation](https://studio.bjanczak.com/). Keep the change focused and explain the resulting behavior. Verify links, examples and asset references relevant to the change.

The public repository includes the Laravel and Media Core source, client source, prebuilt browser assets, documentation and tests. Never include credentials, local installations, generated caches, user media, vendor directories or internal engineering material.

## Local checks

Python 3.13 or later and Git are sufficient for the repository checks:

```bash
python3 .github/scripts/check_repository.py
```

The checks validate the README, referenced artwork, runtime-preserving archive exclusions and pinned GitHub Actions. CodeQL checks the published Python maintenance script and GitHub Actions workflows. Changes to optional services should be described accurately without claiming an integration has been exercised when it has not.

Security reports belong in the [private reporting flow](SECURITY.md).

## Package tests

Use PHP 8.4+ with SQLite, mbstring and the extensions required by Composer, plus Node.js 22+. From a clone of `main`:

```bash
composer install
composer test
npm ci
npm test
```

Browser tests use the committed browser build. Install Playwright Chromium with `npx playwright install chromium`, then run `npm run test:browser`. Optional tools (FFmpeg, LibreOffice, Poppler, AVIF/Imagick, encrypted S3) can cause explicitly identified skips when unavailable. Set `MEDIA_STUDIO_TEST_SOFFICE` to select a trusted LibreOffice executable.

Optional integration tests in `tests/integrations` require their own dependencies and a compatible FLEX Fields checkout at the path declared in that development manifest. Those tests do not make FLEX Fields a runtime dependency of Studio. Do not publish or modify another FLEX package as part of a Studio contribution.

Test source, fixtures, client compilation inputs and development manifests are excluded from Composer archives.
