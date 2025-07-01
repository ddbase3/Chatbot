<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentMemoryFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotService implements IOutput {

        public function __construct(
                private readonly IRequest $request,
                private readonly IAgentContextFactory $agentcontextfactory,
                private readonly IAgentMemoryFactory $agentmemoryfactory,
                private readonly IAgentFlowFactory $agentflowfactory
        ) {}

        public static function getName(): string {
                return 'chatbotservice';
        }

        public function getOutput($out = 'html'): string {
                $prompt = $this->request->post('prompt');

                if (!$prompt) {
                        return 'Please provide a prompt.';
                }

		$memory = $this->agentmemoryfactory->createMemory('sessionmemory');
		$context = $this->agentcontextfactory->createContext('agentcontext', $memory);

		$system = 'Du bist ein extrem sarkastischer Gesprächspartner und benutzt dabei trockene und tiefgründige Witze. Außerdem verwendest Du äußerst gern Emojis.';
		$system .= 'Bitte fasse die wichtigsten Informationen oder den Kontext des letzten Teils des Dialogs in wenigen Sätzen zusammen.';

                $json = <<<JSON
{
  "nodes": [
    {
      "id": "cfg",
      "type": "getconfigurationnode",
      "inputs": {
        "section": "openaiconversation",
        "key": "apikey"
      }
    },
    {
      "id": "ai",
      "type": "simpleopenainode",
      "inputs": {
        "model": "gpt-3.5-turbo"
      }
    },
    {
      "id": "log",
      "type": "loggernode",
      "inputs": {
        "scope": "development"
      }
    },
    {
      "id": "msg",
      "type": "staticmessagenode"
    }
  ],
  "connections": [
    { "from": "cfg", "output": "value", "to": "ai", "input": "apikey" },
    { "from": "__input__", "output": "system", "to": "ai", "input": "system" },
    { "from": "__input__", "output": "prompt", "to": "ai", "input": "prompt" },
    { "from": "ai", "output": "response", "to": "log", "input": "message" },
    { "from": "ai", "output": "response", "to": "msg", "input": "text" }
  ]
}
JSON;

                $data = json_decode($json, true);
		$flow = $this->agentflowfactory->createFromArray('strictflow', $data, $context);
                $outputs = $flow->run(['system' => $system, 'prompt' => $prompt]);

		foreach ($outputs as $output) {
			if (!isset($output['message'])) continue;
			return $output['message'];
		}

                return 'Fehler: ' . json_encode($outputs);
        }

        public function getHelp(): string {
                return 'ChatbotService – nimmt eine Benutzereingabe entgegen, fragt OpenAI und gibt die Antwort aus.';
        }
}

