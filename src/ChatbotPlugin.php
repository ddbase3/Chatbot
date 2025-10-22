<?php declare(strict_types=1);

namespace Chatbot;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;

class ChatbotPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'chatbotplugin';
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED);
	}
}
