# Chatbot

Chatbot provides a configurable BASE3 chat application that is independent of the concrete agent runtime. It consumes the shared AssistantFoundation contracts and owns its complete REST/SSE transport.

## Runtime boundary

`Chatbot\Service\AbstractChatbotService` calls `AssistantFoundation\Api\IAgentExecutionService::execute()` for every agent-backed turn.

- REST uses a collecting event sink and formats the terminal result as JSON.
- SSE uses the Chatbot-owned `SseAgentEventSink`.
- MissionBay and alternative runtimes are selected per chatbot record without changing the chatbot service or UI protocol.
- Runtime-specific configuration is supplied through the shared `IAgentConfigFormService` contract.

Chatbot imports neither MissionBay nor NeuronAi contracts.

## Large prompts and SSE

Browser `EventSource` supports GET only, while chatbot prompts can exceed practical URL limits. Chatbot therefore owns a two-step, single-use turn transport:

1. `chatbotturnprepare` receives the complete turn through POST.
2. `SessionChatbotTurnRequestStore` stores the payload for at most five minutes and returns an opaque random ID.
3. `chatbotturnstream` claims and deletes the turn by ID.
4. The stream endpoint resolves the selected `IChatbotService` and executes it directly.

There is no internal HTTP or cURL request. The session lock is released before the long-running agent execution starts.

The session-backed request store is the default implementation. Multi-node installations can replace `IChatbotTurnRequestStore` through DI with a shared store without changing the browser protocol.

## Configuration

A chatbot instance stores UI settings, prompts, references, transport mode and runtime-specific agent settings in `ISettingsStore`.

Supported transport values are:

- `auto`, resolved as SSE by the browser client
- `sse`
- `rest`

The current browser UI, voice integration, threads and canvas events use the same event names for every agent runtime.

## Conversation identity

The browser creates one stable `conversation_id` per configured chatbot and
stores it in `localStorage`. The ID is included in REST turns and in the
POST-to-ID-to-SSE payload. Before execution, Chatbot replaces any submitted
owner value with a server-generated hash of the authenticated user or anonymous
BASE3 session.

The runtime receives the conversation ID, owner key and chatbot configuration
identity in `AgentExecutionRequest::context`. Runtimes that support persistent
memory can use that scope; runtimes that do not support it remain unaffected.
The current "Start new chat" button creates a new conversation ID and reloads
the widget.

## Chatbot backend selection

The configuration UI has one backend field. It combines direct chatbot services and registered agent runtimes in one list, for example:

- Dummy Chatbot Service
- MissionBay
- Neuron AI

Choosing a runtime activates only that runtime's configuration fields. The chatbot stores `chatbot_backend=runtime:<id>` while Agent Admin stores `agent_runtime=<id>`. Both paths execute through the same AssistantRuntime router.

## Runtime form data contract

`IAgentConfigFormService::assignViewData()` receives persisted or normalized settings. Host displays must not pass values that were already transformed by `settingsToViewValues()`, because runtime-specific structured values such as the MissionBay `agent_flow` would otherwise be converted twice and lost.

## Persisted backend resolution

The public `ChatbotDisplay` resolves the backend and UI settings from the same SettingsStore record edited by `ChatbotConfigDisplay`. Page-component data only provides the `config_group` and `config_name` identity. Legacy page components that still contain a direct `service` value remain supported.

`DummyChatbotService` implements both SSE and REST responses and uses the same `msgid`, `token` and `done` event names as the regular agent-backed service.

## Requirements

- PHP 8.1 or newer
- BASE3 Framework
- AssistantFoundation
- AssistantRuntime
- an `IAgentExecutionService` implementation for agent-backed backends

## License

GPL-3.0. See `LICENSE`.
