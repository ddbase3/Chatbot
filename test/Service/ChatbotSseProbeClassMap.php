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

namespace Base3\Chatbot\Test\Service;

use Base3\Core\ClassMap;
use Base3\Chatbot\Api\IChatbotService;

final class ChatbotSseProbeClassMap extends ClassMap {

	public function &getInstancesByInterface($interface) {
		$instances = $interface === IChatbotService::class
			? [new ChatbotServiceRegistryTestDouble(), new ChatbotSseProbeService()]
			: [];

		return $instances;
	}
}
