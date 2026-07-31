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

use AssistantFoundation\Api\IAssistantResponseExtension;
use Base3\Api\IClassMap;
use RuntimeException;

final class ChatbotExtensionRegistry {

	/** @var array<string,IAssistantResponseExtension>|null */
	private ?array $extensions = null;

	public function __construct(private readonly IClassMap $classMap) {}

	public static function getName(): string {
		return 'chatbotextensionregistry';
	}

	/** @return array<string,IAssistantResponseExtension> */
	public function all(): array {
		if ($this->extensions !== null) {
			return $this->extensions;
		}

		$extensions = [];
		foreach ($this->classMap->getInstancesByInterface(IAssistantResponseExtension::class) as $extension) {
			if (!$extension instanceof IAssistantResponseExtension) {
				throw new RuntimeException('ClassMap returned an invalid assistant response extension instance.');
			}

			$id = $this->normalizeId($extension->id());
			if ($id === '') {
				throw new RuntimeException('Assistant response extension returned an empty technical id.');
			}
			if (isset($extensions[$id])) {
				throw new RuntimeException('Duplicate assistant response extension: ' . $id);
			}

			$extensions[$id] = $extension;
		}

		uasort($extensions, static function(
			IAssistantResponseExtension $a,
			IAssistantResponseExtension $b
		): int {
			$priority = $a->getPriority() <=> $b->getPriority();
			return $priority !== 0 ? $priority : strcmp($a->id(), $b->id());
		});

		$this->extensions = $extensions;
		return $this->extensions;
	}

	private function normalizeId(string $id): string {
		$id = trim($id);
		return preg_match('/^[a-z0-9._-]+$/', $id) === 1 ? $id : '';
	}
}
