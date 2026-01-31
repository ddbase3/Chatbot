<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IAssetResolver;
use Base3\Api\IDisplay;
use Base3\Api\IMvcView;
use Base3\Api\ISchemaProvider;

class ChatbotDisplay implements IDisplay, ISchemaProvider {

	private array $data = [];

	public function __construct(
		private readonly IMvcView $view,
		private readonly IAssetResolver $assetResolver
	) {}

	public static function getName(): string {
		return 'chatbotdisplay';
	}

	// ---------------------------------------------------------------------
	// Render
	// ---------------------------------------------------------------------
	public function getOutput(string $out = 'html', bool $final = false): string {
		$this->view->setPath(DIR_PLUGIN . 'Chatbot');
		$this->view->setTemplate('Content/ChatbotDisplay.php');

		$defaults = [
			'service'        => 'chatbotservice.php',

			// Features
			'use_markdown'   => true,
			'use_icons'      => true,
			'use_voice'      => true,

			// Transport
			'transport_mode' => 'auto',	// auto | sse | websocket | rest

			// Voice config
			'default_lang'   => 'auto'
		];

		$config = array_merge($defaults, $this->data);

		foreach ($config as $tag => $content) {
			$this->view->assign($tag, $content);
		}

		$this->view->assign('resolve', fn($src) => $this->assetResolver->resolve($src));

		return $this->view->loadTemplate();
	}

	public function getHelp(): string {
		return 'Display a configurable Chatbot widget.';
	}

	public function setData($data) {
		$this->data = (array) $data;
	}

	// ---------------------------------------------------------------------
	// JSON Schema
	// ---------------------------------------------------------------------
	public function getSchema(): array {
		return [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [

				'service' => [
					'type' => 'string',
					'description' => 'Service URL (server endpoint)',
					'default' => 'chatbotservice.php'
				],

				'use_markdown' => [
					'type' => 'boolean',
					'description' => 'Enable markdown to HTML conversion',
					'default' => true
				],
				'use_icons' => [
					'type' => 'boolean',
					'description' => 'Show dialog action icons (copy, like, dislike, reload)',
					'default' => true
				],
				'use_voice' => [
					'type' => 'boolean',
					'description' => 'Enable voice controls and TTS/STT',
					'default' => true
				],

				'transport_mode' => [
					'type' => 'string',
					'enum' => ['auto', 'sse', 'websocket', 'rest'],
					'description' => 'Transport protocol for streaming responses',
					'default' => 'auto'
				],

				'default_lang' => [
					'type' => 'string',
					'description' => 'Default language for voice control',
					'default' => 'auto'
				]
			],
			'required' => ['service']
		];
	}
}
