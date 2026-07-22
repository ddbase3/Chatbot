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

use Base3\Api\IClassMap;
use Chatbot\Api\IChatbotService;
use RuntimeException;

/**
 * Resolves configured chatbot backends for prepared SSE turns.
 */
final class ChatbotServiceRegistry {

	/** @var array<string,IChatbotService>|null */
	private ?array $services = null;

	public function __construct(private readonly IClassMap $classMap) {}

	public static function getName(): string {
		return 'chatbotserviceregistry';
	}

	public function has(string $serviceId): bool {
		$serviceId = $this->normalizeServiceId($serviceId);

		return $serviceId !== '' && isset($this->getServices()[$serviceId]);
	}

	public function get(string $serviceId): IChatbotService {
		$serviceId = $this->normalizeServiceId($serviceId);
		if ($serviceId === '' || !isset($this->getServices()[$serviceId])) {
			throw new RuntimeException('Unknown chatbot service: ' . ($serviceId !== '' ? $serviceId : '[empty]'));
		}

		return $this->getServices()[$serviceId];
	}

	/** @return array<string,IChatbotService> */
	private function getServices(): array {
		if ($this->services !== null) {
			return $this->services;
		}

		$this->services = [];
		foreach ($this->classMap->getInstancesByInterface(IChatbotService::class) as $service) {
			if (!$service instanceof IChatbotService) {
				continue;
			}

			$serviceId = $this->normalizeServiceId($service::getName());
			if ($serviceId === '') {
				throw new RuntimeException('Chatbot service returned an empty technical name.');
			}
			if (isset($this->services[$serviceId])) {
				throw new RuntimeException('Duplicate chatbot service: ' . $serviceId);
			}

			$this->services[$serviceId] = $service;
		}

		return $this->services;
	}

	private function normalizeServiceId(string $serviceId): string {
		$serviceId = strtolower(trim($serviceId));

		return preg_replace('/[^a-z0-9._-]+/', '', $serviceId) ?? '';
	}
}
