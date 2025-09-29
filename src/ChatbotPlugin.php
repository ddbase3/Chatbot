<?php declare(strict_types=1);

namespace Chatbot;

use Base3\Api\ICheck;
use Base3\Api\IContainer;
use Base3\Api\IMvcView;
use Base3\Api\IPlugin;
use Base3\Core\Check;
use Base3\Core\MvcView;

class ChatbotPlugin implements IPlugin, ICheck {

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

	// Implementation of ICheck

	public function checkDependencies() {
		return array(
			'moduledpageplugin_installed' => $this->container->get('moduledpageplugin') ? 'Ok' : 'moduledpageplugin not installed',
			'missionbayplugin_installed' => $this->container->get('missionbayplugin') ? 'Ok' : 'missionbayplugin not installed'
		);
	}
}
