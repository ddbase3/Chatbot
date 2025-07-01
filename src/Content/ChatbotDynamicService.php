<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use Base3\Api\IClassMap;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentMemoryFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotDynamicService implements IOutput {

    public function __construct(
        private readonly IRequest $request,
        private readonly IClassMap $classmap,
        private readonly IAgentContextFactory $agentcontextfactory,
        private readonly IAgentMemoryFactory $agentmemoryfactory,
        private readonly IAgentFlowFactory $agentflowfactory
    ) {}

    public static function getName(): string {
        return 'chatbotdynamicservice';
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
      "id": "rev",
      "type": "stringreversernode"
    }
  ]
}
JSON;

        $data = json_decode($json, true);
        $flow = $this->agentflowfactory->createFromArray('dynamicaiflow', $data, $context);
        $outputs = $flow->run(['system' => $system, 'prompt' => $prompt]);

        if (isset($outputs[0]) && isset($outputs[0]['response'])) {
            return $outputs[0]['response'];
        }

        return 'Fehler: ' . json_encode($outputs);
    }

    public function getHelp(): string {
        return 'ChatbotDynamicService – nutzt DynamicAiFlow, um je nach Benutzereingabe automatisch den Flow zu bestimmen.';
    }
}

