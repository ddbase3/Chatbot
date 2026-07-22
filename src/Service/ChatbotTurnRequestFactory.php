<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of Chatbot for BASE3 Framework.
 *
 * Chatbot extends the BASE3 framework with a modular API
 * foundation for flow-based chatbot services and interfaces.
 * It provides reusable components for AI-driven conversations.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/chatbot
 * https://github.com/ddbase3/Chatbot
 **********************************************************************/

namespace Chatbot\Service;

use Base3\Api\IRequest;
use Chatbot\Dto\ChatbotTurnRequest;

/**
 * Creates explicit chatbot turn requests from the current HTTP request.
 */
final class ChatbotTurnRequestFactory {

	public function __construct(
		private readonly IRequest $request,
		private readonly ChatbotConversationContextFactory $conversationContextFactory
	) {}

	public static function getName(): string {
		return 'chatbotturnrequestfactory';
	}

	public function fromCurrentRequest(): ChatbotTurnRequest {
		$prompt = $this->readValue('prompt');
		if ($prompt === null) {
			$prompt = $this->readValue('user');
		}

		$payload = [
			'prompt' => $prompt,
			'config_group' => $this->readValue('config_group'),
			'config_name' => $this->readValue('config_name'),
			'transport_mode' => $this->readValue('transport_mode'),
			'conversation_id' => $this->readValue('conversation_id'),
			'reference' => $this->readValue('reference'),
			'reference_format' => $this->readValue('reference_format'),
			'resume' => $this->readValue('resume'),
			'resume_handle' => $this->readValue('resume_handle'),
			'resume_response' => $this->readValue('resume_response'),
			'resume_responses' => $this->readValue('resume_responses')
		];

		return new ChatbotTurnRequest($this->conversationContextFactory->enrich($payload));
	}

	private function readValue(string $key): mixed {
		$value = $this->request->request($key);

		return $value !== null ? $value : $this->request->get($key);
	}
}
