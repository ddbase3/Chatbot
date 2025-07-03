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

		$systemFactcheck = file_get_contents('plugin/Chatbot/local/Chatbot/systemfactcheck.txt');
		$systemWeather = file_get_contents('plugin/Chatbot/local/Chatbot/systemweather.txt');

		$flow = $this->agentflowfactory->createFromArray('strictflow', $data, $context);

		$outputs = $flow->run([
			'system' => $system,
			'prompt' => $prompt,
			'system-factcheck' => $systemFactcheck,
			'system-weather' => $systemWeather
		]);

		$message = $outputs['msg']['message'] ?? '[Keine Nachricht erhalten]';
		$out = $message;

		$out .= $this->addFactCheck($outputs);
		$out .= $this->addJoke($outputs);
		$out .= $this->addWeather($outputs);

		return $out;
        }

        public function getHelp(): string {
                return 'ChatbotExtendedService – nimmt eine Benutzereingabe entgegen, fragt OpenAI, gibt die Antwort aus und ruft dabei weitere Services auf.';
        }

	// Private methods

	private function addFactCheck(array $outputs): string {
		$out = '';
		$factcheck = $outputs['factchecker']['response'] ?? '';
		if (!empty($factcheck)) {
			$claim = $outputs['claim']['message'] ?? '';
			$data = json_decode($factcheck, true);
			$emoji = ($data['result'] === 'Richtig') ? '✅' : '❌';
			$resultClass = ($data['result'] === 'Richtig') ? 'richtig' : 'falsch';

			$content = ''
				. '<span class="label">Aussage:</span> ' . htmlspecialchars($claim) . '<br />'
				. '<span class="label">Bewertung:</span> <span class="result ' . $resultClass . '">' . $emoji . ' ' . htmlspecialchars($data['result']) . '</span><br />'
				. '<span class="label">Begründung:</span> ' . htmlspecialchars($data['reason']);

			$out .= $this->renderInfoBox('fact', '🧐 Faktencheck', $content);
		}
		return $out;
	}

	private function addJoke(array $outputs): string {
		$out = '';
		$joke = $outputs['trans']['translated'] ?? '';
		if (!empty($joke)) {
			$content = '<span class="label">Joke:</span>' . htmlspecialchars($joke);
			$out .= $this->renderInfoBox('joke', '😂 Witz des Tages', $content);
		}
		return $out;
	}

	private function addWeather(array $outputs): string {
		$out = '';
		$weather = $outputs['ai-weather']['response'] ?? '';
		if (!empty($weather)) {
			$content = '<span class="label">Wetter:</span>' . htmlspecialchars($weather);
			$out .= $this->renderInfoBox('weather', '🌤  Wetterbericht', $content);
		}
		return $out;
	}

	/*
	 * Für Warnungen: renderInfoBox('warning', '⚠️ Warnung', $content)
	 */

	private function renderInfoBox(string $type, string $title, string $contentHtml): string {
		return '<div class="info-box info-' . htmlspecialchars($type) . '">' .
		       '<div class="info-header">' . htmlspecialchars($title) . '</div>' .
		       '<div class="info-body">' . $contentHtml . '</div>' .
		       '</div>';
	}
}

