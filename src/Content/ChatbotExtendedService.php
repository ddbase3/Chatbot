<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentMemoryFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotExtendedService implements IOutput {

        public function __construct(
                private readonly IRequest $request,
                private readonly IAgentContextFactory $agentcontextfactory,
                private readonly IAgentMemoryFactory $agentmemoryfactory,
                private readonly IAgentFlowFactory $agentflowfactory
        ) {}

        public static function getName(): string {
                return 'chatbotextendedservice';
        }

        public function getOutput($out = 'html'): string {

		$prompt = $this->request->post('prompt');
		if (!$prompt) {
			return 'Please provide a prompt.';
		}

		$memory = $this->agentmemoryfactory->createMemory('sessionmemory');
		$context = $this->agentcontextfactory->createContext('agentcontext', $memory);

		$system = file_get_contents('plugin/Chatbot/local/Chatbot/systemprompt.txt');

		$json = file_get_contents('plugin/Chatbot/local/Chatbot/agentflow.json');
                $data = json_decode($json, true);

		$flow = $this->agentflowfactory->createFromArray('strictflow', $data, $context);

		$outputs = $flow->run(['system' => $system, 'prompt' => $prompt]);

		$message = $outputs['msg']['message'] ?? '[Keine Nachricht erhalten]';

		if (isset($outputs['msg']) && isset($outputs['msg']['message'])) {
			return $outputs['msg']['message'];
		}

                return 'Fehler: ' . json_encode($outputs);
        }

        public function getHelp(): string {
                return 'ChatbotExtendedService – nimmt eine Benutzereingabe entgegen, fragt OpenAI, gibt die Antwort aus nud ruft dabei weitere Services auf.';
        }
}

