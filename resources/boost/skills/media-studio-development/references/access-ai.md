# Access, tenants and AI

Register authenticatable models in `principals`; derive `Identity\Principal`
from the actual authenticated model. Resolve the workspace through
`Access\WorkspaceAccess` with the host tenant context. Required Spatie Permission
v8 integrates with ordinary ability checks; Guardian/Shield are optional editors,
not mandatory authorization engines. Never translate stable permission IDs or
accept an arbitrary client-provided PHP model class.

## AI systems are independent

| Feature | Configuration | Optional tables |
| --- | --- | --- |
| Visual labels/frame analysis | `intelligence` | intelligence migrations |
| Text/PDF/OCR projections | `search.content` | search migrations |
| Semantic retrieval | `search.semantic` + Meilisearch | search migrations |
| Chat assistant | `assistant` + Laravel `ai.php` | assistant migrations |

Visual adapters are `google_vision`, `moondream`, `rekognition`; Rekognition needs
its optional AWS SDK. OCR uses the shipped Google Vision adapter and its credential
under `intelligence.providers.google_vision.key`; it does not require enabling
visual auto-tagging. PDF text extraction is not OCR or thumbnail generation.

`search.semantic.embedder` is a Meilisearch settings array with a supported
`openAi|huggingFace|rest` source, not a PHP callback. Apply index settings and
synchronize projections before expecting results. Authorization/current-version
checks remain authoritative over indexed hits.

The assistant allowlists named provider/model definitions in `assistant.models`;
provider credentials live in Laravel AI configuration. It needs an async queue.
It uses authorized metadata/indexed text and explicit supported attachments;
current-file selection does not imply arbitrary original-byte access. Tool actions
execute with the current principal's permissions. Do not claim a file was opened
without an action receipt or that the assistant edits image bytes directly.

Default flags are disabled. Automatic upload analysis/extraction is another opt-in;
it is not a whole-library backfill or exactly-once billing outbox. Keep provider
keys server-side, inputs bounded, queues finite and model capability explicit.
Encryption at rest does not prevent selected input being sent to the configured
external AI provider. Report actual provider verification separately from mocks.

## Development assistants versus product assistants

Laravel Boost supplies development guidelines/skills to coding agents. Studio's
MCP supplies authorized runtime tools to an external client. In-app chat is a third
integration. Installing the development skill must not enable MCP routes, issue a
Studio token or grant access to private files.
