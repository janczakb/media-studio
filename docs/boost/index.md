---
title: "Develop with Laravel Boost"
description: "Give your coding agent Media Studio-specific context using the package's discoverable guidelines and skill."
---

Media Studio ships a **media-studio-development** skill and a small development
guideline in its Laravel package. Laravel Boost can discover and install them for
your supported coding agent. The integration is optional and development-only:
Studio does not require Boost at runtime.

| Included context | Purpose |
| --- | --- |
| Core guideline | Recognize Media Studio work and activate the focused skill |
| Development skill | Verify installed APIs and choose the correct integration boundary |
| Integration reference | React SDK, native Filament, optional FLEX Fields, references, locale and shortcut contracts |
| Storage/processing reference | Private storage, upload lifecycle, current versions, thumbnails and ownership |
| Access/AI reference | Principal/tenant authorization, optional AI systems, provider and runtime boundaries |

## Install and activate

Follow [Boost setup](/boost/setup). The resources ship in the package under:

```text
resources/boost/guidelines/core.blade.php
resources/boost/skills/media-studio-development/SKILL.md
resources/boost/skills/media-studio-development/references/
```

They are resolved from the installed Composer package, so the workflow does not
depend on a local development directory or modification of another plugin.

## What this enables

Ask your coding agent to use `media-studio-development` when adding an upload,
picker, editor, thumbnail recipe, tenant/access integration, search or assistant
configuration. The skill directs it to actual installed class signatures and SDK
declarations, identifies optional packages, and explains the ownership rules that
prevent duplicate uploads or accidental shared-file deletion.

A skill improves available context; it cannot guarantee that an AI understands
every possible host customization. Inspect the generated code and verify its
behavior. [Examples and verification](/boost/workflows) show how to ask for a
reviewable integration with concrete constraints.

## Keyboard and clipboard integration

The skill also covers [Studio shortcuts and clipboard uploads](/keyboard-shortcuts): use instance-scoped commands, preserve editor text input and route pasted files through the existing authorized transfer pipeline.

## Different from runtime AI

Boost helps an agent **write application code**. [Studio MCP](/mcp) supplies
permission-scoped runtime tools, and the [in-app assistant](/assistant) uses the
host's configured Laravel AI models. Installing the skill does not issue a token,
enable MCP/chat, send your files to a provider or grant library access.
