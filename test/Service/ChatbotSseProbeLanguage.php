<?php declare(strict_types=1);

namespace Chatbot\Test\Service;

use Base3\Language\Api\ILanguage;

final class ChatbotSseProbeLanguage implements ILanguage {

	private string $language = 'en';

	public function getLanguage(): string {
		return $this->language;
	}

	public function setLanguage(string $language) {
		$this->language = $language;
	}

	public function getLanguages(): array {
		return ['en'];
	}
}
