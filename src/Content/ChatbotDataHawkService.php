<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IOutput;
use Base3\Api\IRequest;
use MissionBay\Api\IAgentContextFactory;
use MissionBay\Api\IAgentMemoryFactory;
use MissionBay\Api\IAgentFlowFactory;

class ChatbotDataHawkService implements IOutput {

        public function __construct(
                private readonly IRequest $request,
                private readonly IAgentContextFactory $agentcontextfactory,
                private readonly IAgentMemoryFactory $agentmemoryfactory,
                private readonly IAgentFlowFactory $agentflowfactory
        ) {}

        public static function getName(): string {
                return 'chatbotdatahawkservice';
        }

        public function getOutput($out = 'html'): string {

		$prompt = $this->request->post('prompt');
                if (!$prompt) {
                        return 'Please provide a prompt.';
                }

		$memory = $this->agentmemoryfactory->createMemory('sessionmemory');
		$context = $this->agentcontextfactory->createContext('agentcontext', $memory);

		$system = file_get_contents('plugin/Chatbot/local/Chatbot/systempromptdatahawk.txt');

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
	"model": "gpt-3.5-turbo",
        "temperature": 0.5
      }
    },
    {
      "id": "datahawk",
      "type": "datahawkreportnode"
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
    { "from": "ai", "output": "response", "to": "msg", "input": "text" },
    { "from": "ai", "output": "response", "to": "datahawk", "input": "config" }
  ]
}
JSON;

                $data = json_decode($json, true);
		$flow = $this->agentflowfactory->createFromArray('strictflow', $data, $context);
                $outputs = $flow->run(['system' => $system, 'prompt' => $prompt]);

		$report = $outputs['datahawk']['report'] ?? '';
		if (empty($report)) $report = '<p>Keine Daten gefunden.</p>';

		$query = $outputs['msg']['message'] ?? '';
		$response = $outputs['datahawk']['response'] ?? '';
		$columns = $outputs['datahawk']['columns'] ?? '';
		$sql = $outputs['datahawk']['sql'] ?? '';

		$report = '<p>' . $response . '</p>' . $report;

		$report .= '<script>console.log(' . json_encode($prompt) . ');</script>';
		$report .= '<script>console.log(' . json_encode($response) . ');</script>';
		$report .= '<script>console.log(' . json_encode(json_decode($query)) . ');</script>';
		$report .= '<script>console.log(' . json_encode($sql) . ');</script>';
		$report .= '<script>console.log(' . json_encode($columns) . ');</script>';

		if (!empty($report)) return $report;

                return '<p>Fehler: ' . json_encode($outputs) . '</p><p>' . $message . '</p>';
        }

        public function getHelp(): string {
                return 'ChatbotDataHawkService – nimmt eine Benutzereingabe entgegen, fragt OpenAI und gibt die Antwort aus.';
        }
}

