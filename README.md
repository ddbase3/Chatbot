# Chatbot

Chatbot provides a configurable BASE3 chat application that is independent of the concrete agent runtime. It consumes the shared AssistantFoundation contracts and owns its REST/SSE transport, chatbot configuration and server-side conversation endpoints.

## Runtime boundary

`Chatbot\Service\AbstractChatbotService` calls `AssistantFoundation\Api\IAgentExecutionService::execute()` for every agent-backed turn.

- REST uses a collecting event sink and formats the terminal result as JSON.
- SSE uses the Chatbot-owned `SseAgentEventSink`.
- MissionBay and alternative runtimes are selected per chatbot record without changing the chatbot service or UI protocol.
- Runtime-specific configuration is supplied through the shared `IAgentConfigFormService` contract.
- Conversation operations use the runtime-neutral `IAgentConversationService` contract.
- Isolated title and opening-message tasks use `IAgentTextTaskService` and cannot write conversation memory or execute tools.

Chatbot imports neither MissionBay nor NeuronAi contracts.

## Large prompts and SSE

Browser `EventSource` supports GET only, while chatbot prompts can exceed practical URL limits. Chatbot therefore owns a two-step, single-use turn transport:

1. `chatbotturnprepare` receives the complete turn through POST.
2. `SessionChatbotTurnRequestStore` stores the payload for at most five minutes and returns an opaque random ID.
3. `chatbotturnstream` claims and deletes the turn by ID.
4. The stream endpoint resolves the selected `IChatbotService` and executes it directly.

There is no internal HTTP or cURL request. The session lock is released before the long-running agent execution starts.

The session-backed request store is the default implementation. Multi-node installations can replace `IChatbotTurnRequestStore` through DI with a shared store without changing the browser protocol.

## Canonical chatbot configuration

A chatbot instance stores one named settings dataset in `ISettingsStore`. The dataset contains UI options, runtime configuration, reference configuration and the selected agent profiles.

The canonical chatbot UI fields are:

```text
chatbot_backend
use_markdown
use_icons
use_voice
chat_history_enabled
chat_history_panel_mode
automatic_chat_titles
main_headings
first_message_mode
first_messages
ai_notice_text
transport_mode
reference_mode
reference
reference_provider
default_lang
speech_to_text_service
text_to_speech_service
```

`chat_history_panel_mode` accepts:

```text
responsive
open
closed
```

`main_headings` and the first chat message are independent scopes.

- `main_headings` contains presentation headings outside the conversation. With one entry the heading is fixed. With several entries one is selected randomly for each new chat. With no entries no heading is shown. The heading disappears as soon as the conversation contains a message.
- `first_message_mode` accepts `none`, `random`, and `contextual_ai`. This setting controls a real first assistant message stored in the conversation.
- `first_messages` supplies the pool for the random first assistant message and is ignored for the other modes.
- `contextual_ai` creates an isolated model response using the current reference, context profile and a non-executable description of the configured tools. The result is stored as the first real assistant message.

The `base_prompts` and persisted `use_threads` fields are not part of the configuration model. The ModularChatbot uses the ConversationPlugin for server-backed conversations.

`ai_notice_text` is mandatory and is intended for the visible notice beneath the message composer. The configuration display loads its new labels from `lang/Configuration`.

## Conversation identity

A persisted chatbot is identified by its SettingsStore `config_group` and `config_name`. `ChatbotConversationChannelResolver` derives one stable server-side `conversation_channel_id` from that identity. The browser cannot provide or override the channel ID.

A turn may additionally contain a `conversation_id` for one chat inside that channel. The configured conversation-memory backend determines the owner from the authenticated user or active session. No owner or channel value is accepted from the browser.

Different chatbot settings records therefore receive different conversation channels even when they use the same runtime, agent flow and memory profile.

Conversation memory remains optional. A chatbot without a `memory_profile` renders and executes normally, but its conversation endpoint URLs are empty and its history is not retained by a conversation memory.

## Conversation endpoints

The public display provides these URLs when conversation memory is configured:

```text
chatbotconversationstate
chatbotconversationcreate
chatbotconversationactivate
chatbotconversationrename
chatbotconversationdelete
chatbotconversationtitle
```

All endpoints resolve the chatbot configuration from `config_group` and `config_name`. Mutating operations accept POST. The state endpoint accepts GET and POST.

Responses use this shape:

```json
{
  "ok": true,
  "data": {
    "state": {}
  }
}
```

Errors use:

```json
{
  "ok": false,
  "error": {
    "code": "conversation_error",
    "message": "..."
  }
}
```

The state contains the conversation list, the active conversation, its messages, the assistant node ID and warnings.

When no conversation exists, the state service creates one canonical conversation and persists its opening message. Deleting the last conversation creates a new empty conversation using the configured main heading and first-message settings.

## Automatic titles

`chatbotconversationtitle` creates a title only when:

- automatic titles are enabled,
- the active title source is `temporary`,
- one complete user/assistant turn exists.

The task receives only the first complete turn. It does not include context or tool profiles, cannot execute tools and cannot write memory. Manual titles have source `manual` and are never overwritten.

A title-task failure leaves the temporary title unchanged and is written to the configured logger.

## Chatbot backend selection

The configuration UI has one backend field. It combines direct chatbot services and registered agent runtimes in one list, for example:

- Dummy Chatbot Service
- MissionBay
- Neuron AI

Choosing a runtime activates only that runtime's configuration fields. The chatbot stores `chatbot_backend=runtime:<id>` while Agent Admin stores `agent_runtime=<id>`. Both paths execute through the same AssistantRuntime router.

The Dummy Chatbot Service also uses the canonical opening-message configuration. It no longer owns a separate hard-coded greeting pool.

## Runtime form data contract

`IAgentConfigFormService::assignViewData()` receives persisted or normalized settings. Host displays must not pass values that were already transformed by `settingsToViewValues()`, because runtime-specific structured values such as a MissionBay `agent_flow` would otherwise be converted twice and lost.

## Public display

`Chatbot\Content\ChatbotDisplay` remains the stable public BASE3 display. It resolves the chatbot backend, SettingsStore identity, feature configuration and service URLs, then delegates rendering to `UiFoundation\Api\IChatbotDisplay`.

ClientStack owns the browser implementation. The ModularChatbot is the active implementation and receives optional response extensions through the generic extension configuration supplied by Chatbot.

## Speech services per chatbot instance

`ChatbotConfigDisplay` stores speech selections in the SettingsStore record identified by `config_group` and `config_name`. The public `ChatbotDisplay` uses the same identity and never exposes provider service IDs to the browser.

The browser-facing `realtimespeechtotextsession` and `texttospeech` outputs load the selected service from that chatbot record before delegating to the neutral AssistantFoundation speech contracts. Different chatbot instances can therefore use different STT and TTS services without a global client-side selection.

An empty STT or TTS selection keeps the corresponding browser speech provider active.

## Requirements

- PHP 8.1 or newer
- BASE3 Framework
- AssistantFoundation
- AssistantRuntime
- UiFoundation
- an `IChatbotDisplay` implementation from ClientStack or the host project
- an `IAgentExecutionService` implementation for agent-backed backends

## License

GPL-3.0. See `LICENSE`.
