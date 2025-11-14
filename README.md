# MissionBay Chatbot Module

This repository contains a modular, flow-based chatbot built with the [MissionBay Agent System](https://github.com/ddbase3/MissionBay). It was originally developed as part of a larger web project and has been extracted for independent reuse, maintenance, and deployment.

## Features

* **Flow-based architecture** using JSON-defined agent flows
* **OpenAI Chat API integration** with dynamic prompts and system behavior
* **Session memory support** for context-aware conversations
* **High RAG capabilities** (Retrieval-Augmented Generation)
* **Modular node structure** allows for flexible extension and reuse
* **Web-based interface** using jQuery and HTML/CSS
* **Multiple backends** for static and dynamic flow execution

## Components

### 1. `ChatbotPageModule`

A page module that renders the chatbot UI. Implements `IMvcView` and `ISchemaProvider` and integrates into the Base3 content framework.

### 2. `ChatbotService`

A service that loads a pre-defined JSON flow and runs it with user input and session memory. Designed for structured conversations and logging.

### 3. `ChatbotDynamicService`

An alternative service that dynamically builds and executes flows. Demonstrates how behavior can be modified programmatically.

### 4. `ChatbotPageModule.php` (Template)

An HTML/CSS/JS frontend template that renders the chat UI, handles user input, and asynchronously calls the chatbot backend.

## Usage

### Run Static Flow (via `ChatbotService`)

```php
POST /chatbotservice.php
prompt=Your+message+here
```

The service fetches the OpenAI API key from configuration, builds a predefined flow, and returns the response.

## Example Flow (Static)

```json
{
  "nodes": [
    {"id": "cfg", "type": "getconfigurationnode", "inputs": {"section": "openai", "key": "apikey"}},
    {"id": "ai", "type": "simpleopenainode", "inputs": {"model": "gpt-3.5-turbo"}},
    {"id": "log", "type": "loggernode", "inputs": {"scope": "development"}},
    {"id": "msg", "type": "staticmessagenode"}
  ],
  "connections": [
    {"from": "cfg", "output": "value", "to": "ai", "input": "apikey"},
    {"from": "__input__", "output": "system", "to": "ai", "input": "system"},
    {"from": "__input__", "output": "prompt", "to": "ai", "input": "prompt"},
    {"from": "ai", "output": "response", "to": "log", "input": "message"},
    {"from": "ai", "output": "response", "to": "msg", "input": "text"}
  ]
}
```

## Frontend UI

* **HTML structure**: Simple chat layout with a chat log and input form
* **JS behavior**: Handles user submission, sends prompt via AJAX, scrolls chat to response
* **CSS styling**: Differentiates user and assistant messages, styles chatbox

## Configuration

Requires the following config section for OpenAI access:

```json
{
  "openai": {
    "apikey": "sk-..."
  }
}
```

## Requirements

* PHP 8+
* Base3 Framework with MissionBay Agent system
* OpenAI API key

## License

GPL License. See `LICENSE` file.

## Author

Developed by \[Daniel Dahme] as part of the [BASE3](https://base3.de) ecosystem.

