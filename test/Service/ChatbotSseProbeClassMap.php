<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Core\ClassMap;
use Chatbot\Api\IChatbotService;

final class ChatbotSseProbeClassMap extends ClassMap {

	public function &getInstancesByInterface($interface) {
		$instances = $interface === IChatbotService::class
			? [new ChatbotServiceRegistryTestDouble(), new ChatbotSseProbeService()]
			: [];

		return $instances;
	}
}
