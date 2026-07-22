# Chatbot

Chatbot provides a configurable BASE3 chat application that is independent of the concrete agent runtime. It consumes the shared AssistantFoundation contracts and currently uses EventTransport only as its HTTP event-stream adapter.

## Runtime boundary

`Chatbot\Service\AbstractChatbotService` calls `AssistantFoundation\Api\IAgentExecutionService::execute()` for both REST and streaming requests.

- REST uses a collecting event sink and formats the terminal result as JSON.
- Streaming creates an `EventStreamAgentEventSink` and forwards runtime events to the browser.
- MissionBay and alternative runtimes are selected per chatbot record without changing the chatbot service or UI protocol.

Runtime-specific configuration fields are supplied through `IAgentConfigFormService`. Its concrete composite implementation lives in `AssistantRuntime`; Chatbot imports neither MissionBay nor NeuronAi contracts.

## Large prompts and SSE

The existing EventTransport POST-to-ID-to-GET sequence remains supported:

1. The browser submits the complete prompt payload through POST.
2. EventTransport stores it temporarily and returns an opaque request ID.
3. Browser `EventSource` opens the GET stream using that ID.
4. The server consumes the stored payload and invokes the chatbot endpoint.

This avoids URL-length limits while retaining browser-native SSE. A later cleanup can move the request store and stream endpoint into Chatbot without changing `IAgentExecutionService`.

## Configuration

A chatbot instance stores UI settings, transport mode, prompts, references, and runtime-specific agent settings in `ISettingsStore`.

Supported transport values are:

- `auto`
- `sse`
- `websocket`
- `rest`

The current browser UI, voice integration, threads, and canvas events remain compatible with the existing event names.

## Requirements

- PHP 8.1 or newer
- BASE3 Framework
- AssistantFoundation
- an `IAgentExecutionService` implementation
- EventTransport while the current SSE adapter is in use

## License

GPL-3.0. See `LICENSE`.

## Chatbot backend selection

The configuration UI has one backend field. It combines direct chatbot
services and registered agent runtimes in one list, for example:

- Dummy Chatbot Service
- MissionBay
- Neuron AI

Choosing a runtime activates only that runtime's configuration fields. The
chatbot stores `chatbot_backend=runtime:<id>` while Agent Admin stores
`agent_runtime=<id>`. Both paths execute through the same AssistantRuntime
router.

## Runtime form data contract

`IAgentConfigFormService::assignViewData()` receives persisted or normalized
settings. Host displays must not pass values that were already transformed by
`settingsToViewValues()`, because runtime-specific structured values such as the
MissionBay `agent_flow` would otherwise be converted twice and lost.

## Persisted backend resolution

The public `ChatbotDisplay` resolves the backend and UI settings from the same
SettingsStore record edited by `ChatbotConfigDisplay`. Page-component data only
provides the `config_group` and `config_name` identity. This prevents a saved
direct service such as `DummyChatbotService` from falling back to the host's
default agent runtime. Legacy page components that still contain a direct
`service` value remain supported.

`DummyChatbotService` implements both SSE and REST responses and uses the same
`msgid`, `token` and `done` event names as the regular chatbot client.
