# Contributing

Help improve Media Studio through actionable bug reports, documentation corrections and proposals grounded in a media workflow.

Before opening a pull request, check existing issues and read the [documentation](https://studio.bjanczak.com/). Keep the change focused and explain the resulting behavior. Verify links, examples and asset references relevant to the change.

This public repository currently contains the product overview, artwork and repository tooling. The application source is maintained separately. Do not submit package source or private installation data to this repository.

## Local checks

Python 3.13 or later and Git are sufficient for the repository checks:

```bash
python3 .github/scripts/check_repository.py
```

The checks validate the README, referenced artwork, runtime-preserving archive exclusions and pinned GitHub Actions. CodeQL checks the published Python maintenance script and GitHub Actions workflows. Changes to optional services should be described accurately without claiming an integration has been exercised when it has not.

Security reports belong in the [private reporting flow](SECURITY.md).
