<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentMemoryFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotLlamaService implements IOutput {

        public function __construct(
                private readonly IRequest $request,
                private readonly IAgentContextFactory $agentcontextfactory,
                private readonly IAgentMemoryFactory $agentmemoryfactory,
                private readonly IAgentFlowFactory $agentflowfactory
        ) {}

        public static function getName(): string {
                return 'chatbotllamaservice';
        }

        public function getOutput($out = 'html'): string {
                $prompt = $this->request->post('prompt');

                if (!$prompt) {
                        return 'Please provide a prompt.';
                }

		$memory = $this->agentmemoryfactory->createMemory('sessionmemory');
		$context = $this->agentcontextfactory->createContext('agentcontext', $memory);

		$system = 'Du bist ein extrem sarkastischer Gesprächspartner und benutzt dabei trockene und tiefgründige Witze. Außerdem verwendest Du äußerst gern Emojis.';

                $json = <<<JSON
{
  "nodes": [
    {
      "id": "cfg",
      "type": "getconfigurationnode",
      "inputs": {
        "section": "ollamaconversation",
        "key": "endpoint"
      }
    },
    {
      "id": "ai",
      "type": "simplellamanode",
      "inputs": {
        "model": "llama3"
      }
    }
  ],
  "connections": [
    { "from": "cfg", "output": "value", "to": "ai", "input": "endpoint" },
    { "from": "__input__", "output": "system", "to": "ai", "input": "system" },
    { "from": "__input__", "output": "prompt", "to": "ai", "input": "prompt" }
  ]
}
JSON;

                $data = json_decode($json, true);
		$flow = $this->agentflowfactory->createFromArray('strictflow', $data, $context);
                $outputs = $flow->run(['system' => $system, 'prompt' => $prompt]);

		$message = $outputs['ai']['response'] ?? '[Keine Nachricht erhalten]';
		return $message;

		// return 'Fehler: ' . json_encode($outputs);
        }

        public function getHelp(): string {
                return 'ChatbotLlamaService – nimmt eine Benutzereingabe entgegen, fragt Das Ollama Modell und gibt die Antwort aus.';
        }
}

