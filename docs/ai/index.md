---
title: "AI in Media Studio"
description: "Configure visual labels, OCR, semantic retrieval and an assistant as independent optional capabilities."
---

AI is optional. Studio works without an image provider, OCR service, embedding
model or conversational assistant. Each capability has its own switch, worker and
access checks. Turning on chat does not enable automatic analysis of your library.

| Capability | Purpose | Start here |
| --- | --- | --- |
| Image labels and descriptions | Analyze visual content and review independent AI suggestions | [Visual analysis](/ai-image-labels) |
| Video analysis | Sample bounded frames and retain timecodes | [Video analysis](/ai/video-analysis) |
| OCR | Extract text from images and textless PDF pages | [Image and PDF OCR](/ai/ocr) |
| Text/PDF extraction | Index existing text without a generative AI call | [Content extraction](/search-content-indexing) |
| Semantic/hybrid search | Find assets by meaning through the configured Meilisearch embedder | [Semantic search](/semantic-search) |
| In-app assistant | Discuss indexed file context, use explicit attachments and perform authorized actions | [Assistant](/assistant) |
| External AI clients | Connect tools such as an MCP client to Studio | [MCP documentation](/mcp) |
| AI image editing in a form | Preview candidates and explicitly publish the selected result | [Image editing integrations](/ai/image-editing) |

## Follow one capability from setup to verification

1. Read [setup and dependencies](/ai/setup); install only the migrations needed.
2. Select [provider configuration](/ai/providers). Visual analysis, embeddings and chat use independent provider settings.
3. Configure limits and queues using [AI configuration](/ai/configuration).
4. Test a single authorized file or chat before enabling automatic admission.
5. Deploy [workers, retention and diagnostics](/ai/operations).

Manual tags remain separate from AI suggestions. OCR retains source-language text;
interface language selection does not translate documents. Assistant file context
is bounded and permission-scoped, not permission to read every original. Storage
encryption protects stored data according to its configured policy; it does not
prevent an explicitly configured external AI provider from receiving the input
needed for analysis.
