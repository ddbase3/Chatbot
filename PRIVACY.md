# Chatbot Privacy and Data Processing

> This document describes the data processing that is directly visible in the Chatbot plugin. It is technical documentation, not a legal privacy notice. Provider-specific processing, legal bases, retention periods, and deployment-specific controls must be documented for the concrete installation.

## Scope

Chatbot provides the browser-facing chat layer for BASE3 and coordinates runtime-neutral assistant services. Depending on configuration, it can process user prompts, assistant responses, conversation history, references, interaction approvals, speech-related data, chatbot configuration, and technical session or state identifiers.

The plugin does not implement a language model, an embedding provider, a retrieval backend, or a concrete conversation-memory backend. Those functions are accessed through shared interfaces and may be provided by other components.

## Main processing paths

| Processing path | Data that may be involved | Storage in Chatbot |
|---|---|---|
| Chat turn | User prompt, resume payload, reference data, conversation and turn IDs, chatbot configuration | Prepared SSE turns may be held temporarily in the session |
| REST response | Assistant result, interaction state, errors | No dedicated Chatbot persistence |
| SSE response | Streamed agent events, assistant tokens, interaction state, errors | No dedicated Chatbot persistence |
| Conversation API | Conversation metadata, messages, titles, warnings, interaction states | Persistent data is delegated to the configured conversation service |
| Conversation draft | Opening message, heading, draft messages, generated IDs | Session-backed draft for up to one hour |
| Automatic title | First complete user and assistant turn, reference data, channel ID | Result is written through the configured conversation service |
| Contextual opening message | Reference data, language, current date and time, assistant configuration | Result may become the first conversation message |
| Speech-to-text session | Selected service ID, language, optional normalized context | No dedicated Chatbot persistence |
| Text-to-speech | Text, language, service-specific options, generated audio | Audio is streamed, not stored by Chatbot |
| Turn cancellation | Turn ID and a hash derived from the PHP session ID | Temporary `IStateStore` marker for up to 600 seconds |
| Chatbot configuration | UI settings, runtime settings, profile IDs, references, speech selections | Stored through `ISettingsStore` |

## User prompts and agent requests

For agent-backed turns, Chatbot builds an `AgentExecutionRequest`. The request can contain:

- the configured system prompt,
- the user prompt or resume response text,
- resume data for suspended executions,
- current reference data,
- the server-derived conversation channel ID,
- the conversation ID,
- the turn ID,
- the chatbot configuration group and name,
- the chatbot settings used by the active runtime.

Chatbot then delegates the request to `IAgentExecutionService`.

The concrete runtime decides which model, tool, retrieval source, memory implementation, or external service receives which parts of this data. Those downstream data flows are not defined by this plugin and must be documented by the active runtime and provider configuration.

## Temporary storage for SSE turns

The default `SessionChatbotTurnRequestStore` stores a prepared turn in the PHP session because browser `EventSource` starts an SSE stream through GET while the original payload can be too large for a URL.

The stored entry contains:

- creation time,
- expiration time,
- chatbot service ID,
- the serialized `ChatbotTurnRequest` payload.

The identifier is generated from 24 random bytes and represented as 48 hexadecimal characters.

Entries expire after 300 seconds. A successful `claim()` removes the entry immediately, making the identifier single-use. Expired entries are removed when the store is accessed again.

The default implementation therefore provides short-lived session storage, not durable conversation storage.

## Conversation drafts

Before a new conversation is materialized in the configured conversation backend, Chatbot can maintain a transient draft in the current session.

A draft may contain:

- a random draft ID,
- a generated conversation ID,
- the opening heading,
- an optional first assistant message,
- draft messages,
- creation time.

Drafts are scoped to the server-derived chatbot channel and are stored through `ISession` under `chatbot_conversation_drafts`.

Drafts expire after 3600 seconds. They are also removed when materialization completes successfully.

## Persistent conversations

Conversation persistence is optional. It is enabled only when the chatbot settings contain a conversation memory profile.

Chatbot delegates conversation operations to `IAgentConversationService`, including:

- reading state,
- creating and activating conversations,
- appending messages,
- renaming conversations,
- deleting conversations.

The concrete conversation service determines:

- the storage backend,
- the owner model,
- retention periods,
- deletion behavior below the API boundary,
- backup handling,
- any additional metadata that is stored.

Chatbot itself does not define those persistence rules.

## Conversation ownership and channel isolation

Chatbot does not accept a browser-provided conversation owner or channel ID.

The conversation channel is derived server-side from the Settings Store `config_group` and `config_name` using SHA-256. The resulting value separates different configured chatbot instances even if they use the same agent runtime.

The configured conversation-memory implementation is responsible for determining the actual owner, for example from an authenticated user or active session.

## Conversation deletion

The conversation delete endpoint calls `IAgentConversationService::deleteConversation()` for the selected conversation. After the last persistent conversation is deleted, Chatbot can create a new transient draft for continued UI operation.

Whether deletion immediately removes all underlying data, removes secondary indexes, or affects backups depends on the configured conversation backend. That behavior must be documented by that backend.

## Automatic titles

Automatic title generation is optional.

When enabled, Chatbot extracts the first complete user and assistant turn from the active conversation. Each extracted text is normalized to plain text and limited to 1200 characters before it is submitted to `IAgentTextTaskService`.

The title task also receives the current reference and the derived conversation channel ID. The returned title is normalized and limited to 100 characters before it is stored through the conversation service.

A title-task failure is logged at warning level with:

- `conversation_id`,
- `chatbot_config_group`,
- `chatbot_config_name`,
- the exception object.

The configured logger backend determines storage, access, rotation, and retention of this warning.

## Contextual opening messages

When `first_message_mode` is `contextual_ai`, Chatbot sends an isolated text task through `IAgentTextTaskService`.

The task can include:

- the selected language,
- current date and time,
- timezone,
- current page or application reference data,
- the active assistant configuration needed by the runtime.

The generated text is stripped of HTML and common Markdown formatting and is limited to 500 characters before it becomes the first assistant message.

Because this task can use an externally hosted model through the selected runtime, reference data may leave the local installation depending on that runtime configuration.

## Interaction requests and suspended executions

Agent runtimes can return interaction requests that require user input or approval. Chatbot may expose to the client:

- suspension ID,
- lifecycle and status,
- creation and expiration time,
- resume handle while the suspension is active,
- request title, message, summary, risk, and kind,
- resolution outcome and user decisions.

The persistent lifecycle of suspensions is handled through `IAgentSuspensionRepository`. Storage and retention are therefore defined outside Chatbot.

## Turn cancellation state

Cancellation markers are stored through `IStateStore` under a key composed from:

- the prefix `chatbot.turn.cancel.`,
- a SHA-256 hash of the current PHP session ID,
- the normalized browser-generated turn ID.

The marker contains the boolean value `true` and has a TTL of 600 seconds. It is normally deleted when turn processing finishes.

The raw PHP session ID is not written into the state-store key.

## Speech-to-text sessions

The `realtimespeechtotextsession` endpoint resolves the speech service from the chatbot settings. The browser cannot select an arbitrary configured service ID through the endpoint.

The request passed to `IRealtimeSpeechToTextSessionService` contains:

- the configured service ID,
- an optional language,
- optional context text.

Control characters are removed from the context and the value is limited to the last 4000 bytes.

The returned realtime session object is sent to the browser in a response marked `Cache-Control: no-store, private` when Chatbot owns the final response.

The actual audio path is implementation-dependent. In a browser-direct realtime provider design, audio may be transmitted from the browser to the selected speech provider rather than through Chatbot. The active speech-service implementation must document endpoint location, credentials, provider retention, and any external transfer.

## Text-to-speech

The `texttospeech` endpoint accepts a JSON body containing:

- `text`,
- `language`,
- optional service-specific `options`.

The configured service ID is resolved server-side from the chatbot settings. Chatbot delegates the request to `ITextToSpeechService` and streams the resulting audio to the browser.

When Chatbot owns the final response, the audio stream uses `Cache-Control: no-store, private, no-transform` and disables common response buffering where possible.

Chatbot does not write the generated audio to a file or dedicated data store.

The selected text-to-speech provider may still process or retain the submitted text or generated audio according to its own implementation and service terms.

## Chatbot settings

Chatbot stores configuration through `ISettingsStore`. Depending on the configured chatbot, a settings record may contain:

- runtime and backend selection,
- UI feature flags,
- conversation-memory profile IDs,
- context or tool profile IDs,
- system and suggestion prompts,
- main headings and opening messages,
- reference mode and reference data,
- speech-to-text and text-to-speech service selections,
- language and transport settings,
- additional runtime-specific configuration fields.

The concrete `ISettingsStore` backend determines the physical storage location, encryption, access control, backups, and retention.

Administrators should avoid placing secrets or unnecessary personal data into general chatbot settings unless a specific runtime contract requires them.

## References and page context

Chatbot supports reference data that can describe the current application context. The exact structure is configuration-dependent.

Reference data can be passed into:

- normal agent execution,
- conversation requests,
- contextual opening-message generation,
- automatic title generation.

References should therefore be treated as potentially sensitive input. Only the context required for the chatbot use case should be included.

## Logs and error responses

Chatbot does not implement a general prompt logger.

However, several endpoints return exception messages in error responses, and SSE runtime errors can include technical error information such as exception message, class, and code in the event payload. Operators should consider the sensitivity of exception text produced by connected runtimes and services.

The only direct logger use in this component is the warning emitted when automatic title generation fails.

## Browser sessions and cookies

Chatbot relies on the surrounding session infrastructure and, in one default temporary turn store, the native PHP session. The plugin does not define the session cookie policy itself.

Session cookie attributes, session storage, rotation, timeout, and transport security are deployment responsibilities of the host application and session implementation.

## Response extensions

Chatbot can load optional assistant response extensions. Enabled extensions can:

- append model instructions to the system prompt,
- supply client-side plugin modules and options,
- render structured assistant response content in the browser.

Extension enablement is stored as technical boolean settings under `chatbot-extensions/default`.

Each installed extension plugin can introduce additional data processing. For example, a map renderer may contact an external tile service. Such behavior belongs in the privacy documentation of the extension component.

## Data minimization guidance

For a privacy-conscious deployment:

- keep reference payloads limited to information required for the current task,
- do not store provider secrets directly in general chatbot settings when a dedicated secret mechanism is available,
- configure conversation memory only when history is required,
- define retention and deletion rules for the selected conversation and suspension backends,
- document the actual model, tool, retrieval, STT, and TTS providers used by each chatbot configuration,
- review runtime error output before exposing technical details to untrusted users,
- keep session and state-store retention consistent with the transient TTLs described above.

## Installation-specific documentation required

Before production use, the installation should document at least:

- the selected agent runtime and model providers,
- whether prompts or references leave the controlled infrastructure,
- conversation-memory storage and retention,
- suspension storage and retention,
- Settings Store and State Store backends,
- session storage and cookie configuration,
- speech-to-text and text-to-speech providers,
- provider processing regions and retention policies,
- logging backends and access controls,
- deletion behavior including backups,
- enabled response extensions and any additional external browser requests they introduce.
