---
title: "AI image editing integrations"
description: "Connect form editing to explicit candidate selection and authoritative Studio version publication."
---

AI visual analysis, chat and image editing are separate capabilities. Enabling
`intelligence` or the assistant does not enable an image-generation/edit provider.

Studio-backed FLEX Fields upload integrations can expose an AI image action when
the host configures it. The host owns the generation provider and publication
callback. The form should preview generated candidates; only an explicit **Use
this version** selection publishes the accepted result. A preview or rejected
candidate must not silently replace the Studio original.

Follow [FLEX Fields image editing](/flex-fields/image-editing) for the actual field
methods, callback contract, error handling and standard editor integration.
Follow [Studio versions](/media-versions) for keep-history versus replacement
retention. Do not use a temporary candidate URL as the durable media reference.

The publication path must preserve the entry's authoritative identity, validate
its current revision and workspace/source access, publish the new version and
admit the configured thumbnail recipes. A generated image with no thumbnail is
not evidence that the selected reference needs another upload. Per-field
replacement and conversion behavior belongs to PHP field configuration.

This page does not advertise a turnkey generative editor in standalone Studio:
the existing integration requires the host's configured callbacks. Filament and
FLEX Fields remain optional; [provider selection](/ai/providers) for chat/labels
does not install either adapter.
