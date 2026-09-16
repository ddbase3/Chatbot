# Chatbot FAQ

## What is the Chatbot plugin?

Chatbot provides a configurable chat application for BASE3. It owns the browser-facing chat transport, chatbot configuration, conversation endpoints, speech endpoints, opening-message behavior, automatic title generation, cancellation handling, and the integration boundary to runtime-neutral assistant services.

The plugin is intentionally independent of a concrete agent engine. Agent-backed turns are executed through the shared assistant contracts selected by the active runtime configuration.

## Does Chatbot contain or bundle a language model?

No. Chatbot does not implement a language model and does not hard-code a specific AI provider. It passes configured agent requests to the active `IAgentExecutionService` implementation.

The actual model, provider, tool set, retrieval configuration, memory backend, and other runtime behavior are determined outside this plugin by the selected assistant runtime and its configuration.

## Which chat transports are supported?

Chatbot supports REST-style JSON responses and Server-Sent Events (SSE).

The configured `transport_mode` can be `auto`, `sse`, or `rest`. SSE is used for streamed events, while REST collects the turn result and returns it as JSON.

## Why does SSE use a separate prepare step?

Browser `EventSource` uses GET requests, but chatbot prompts and turn payloads can be too large for a practical URL. Chatbot therefore uses a two-step transport:

1. `chatbotturnprepare` receives the full turn through POST.
2. The turn is stored under a random single-use identifier.
3. `chatbotturnstream` claims that identifier and starts the SSE execution.
4. Claiming the turn deletes it from the temporary store.

The default pending-turn store keeps unclaimed entries for at most five minutes.

## Where are prepared SSE turns stored?

The default implementation stores them in the PHP session under a random 48-character hexadecimal identifier.

Prepared turns are single-use and expire after 300 seconds. The store can be replaced through the `IChatbotTurnRequestStore` interface, for example when a shared backend is required in a multi-node deployment.

## Does Chatbot support multiple conversations?

Yes, when a conversation memory profile is configured for the chatbot instance.

The browser can create, activate, rename, delete, and inspect conversations through dedicated endpoints. The actual conversation persistence is delegated to the configured `IAgentConversationService` implementation.

## What happens when conversation memory is not configured?

The chatbot can still render and execute turns, but persistent conversation history is unavailable. Conversation endpoint URLs are not exposed by the public display when no memory profile is configured.

## How is a chatbot conversation channel identified?

A configured chatbot is identified by its Settings Store `config_group` and `config_name`.

`ChatbotConversationChannelResolver` derives a stable server-side channel ID by hashing that identity. The browser cannot provide or override the channel ID.

A separate `conversation_id` identifies one conversation within that channel.

## Does the browser choose the conversation owner?

No. Chatbot does not accept an owner identifier from the browser. Ownership is determined by the configured conversation-memory implementation, typically from the authenticated user or active session context.

## What are conversation drafts?

A new conversation is initially represented as a session-scoped draft. This allows Chatbot to prepare headings and optional opening assistant messages before the conversation is materialized in the configured conversation backend.

Drafts are stored in the session and expire after one hour. Once materialized, the draft is removed.

## What opening-message modes are available?

The supported modes are:

- `none`
- `random`
- `contextual_ai`

`none` creates no first assistant message. `random` chooses from configured messages. `contextual_ai` creates an isolated text task using the current language, date and time, page reference, and the configured assistant capability context.

A main heading is separate from the first assistant message and is not itself part of the conversation history.

## How are automatic conversation titles generated?

When automatic titles are enabled and a conversation still has a temporary title, Chatbot can create a title from the first complete user and assistant turn.

Only the first complete turn is used. The title task is executed through `IAgentTextTaskService`. Manual titles are marked separately and are not overwritten by automatic title generation.

## Can Chatbot expose actions that require user approval?

Yes. Agent executions can return an interaction-required state with one or more interaction requests and a resume handle. Chatbot exposes these states to the client and can later submit a resume response.

The policy that decides which actions require approval belongs to the active agent runtime, not to Chatbot itself.

## Can an active turn be cancelled?

Yes. The browser can submit a `turn_id` to the cancellation endpoint.

Cancellation markers are stored in `IStateStore` for up to 600 seconds and are scoped to a hash of the current PHP session ID plus the normalized turn ID. They are normally removed when the turn completes.

## How are response extensions integrated?

Chatbot discovers implementations of `IAssistantResponseExtension`. Enabled extensions may add model instructions and client plugin configuration.

The enabled extension set is stored in the named settings dataset `chatbot-extensions/default`. The concrete renderers themselves may live in separate plugins.

## Does Chatbot support speech input?

Yes, if configured. A chatbot instance can select a realtime speech-to-text service.

The `realtimespeechtotextsession` endpoint creates a short-lived browser session through `IRealtimeSpeechToTextSessionService`. The request can include a language and up to 4000 characters of normalized context.

When the speech-to-text selection is empty, the browser-side implementation remains active. The value `off` disables the function.

## Does Chatbot support spoken answers?

Yes, if configured. The `texttospeech` endpoint accepts text, language, and service-specific options, then delegates streaming audio generation to `ITextToSpeechService`.

The generated audio is streamed directly to the client. The HTTP response is marked `no-store` and `private` when Chatbot owns the final response.

## Can different chatbot instances use different speech services?

Yes. Speech selections are stored in the same settings record as the chatbot instance. The browser does not select an arbitrary provider ID for each request. The server resolves the configured service from `config_group` and `config_name`.

## What data can be passed to the agent runtime?

A normal agent-backed turn can include the system prompt, user prompt, resume information, page or application reference data, conversation ID, turn ID, chatbot configuration identity, and the chatbot settings required by the active runtime.

The active runtime determines how this information is processed further and whether external providers or tools receive any of it.

## Does Chatbot log complete conversations?

The plugin does not contain a general-purpose prompt or conversation logger.

One explicit logger call exists for failures during automatic title generation. That warning includes the conversation ID, chatbot configuration group and name, and the exception object. Other logging behavior may be provided by the surrounding runtime or infrastructure.

## Where are chatbot settings stored?

Each chatbot instance uses one named dataset in `ISettingsStore`. The dataset can contain UI options, runtime configuration, memory and context profile identifiers, references, speech-service selections, and related settings.

The storage backend and retention policy of `ISettingsStore` are determined by the host composition.

## Does Chatbot make direct HTTP calls to agent runtimes?

The normal turn path does not create an internal HTTP or cURL request. It calls the configured assistant services directly through PHP interfaces.

External network traffic may still occur inside the selected runtime, model provider, speech provider, retrieval provider, or tool implementation.

## Where can I find privacy and data-processing information?

See [PRIVACY.md](../PRIVACY.md).
