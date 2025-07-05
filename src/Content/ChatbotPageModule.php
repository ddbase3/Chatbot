<?php declare(strict_types=1);

namespace Chatbot\Content;

use Base3\Api\IMvcView;
use Base3\Api\ISchemaProvider;
use ModuledPage\Page\AbstractModuleContent;

class ChatbotPageModule extends AbstractModuleContent implements ISchemaProvider {

	public function __construct(private readonly IMvcView $view) {}

	// Implementation of IBase

	public static function getName(): string {
		return 'chatbotpagemodule';
	}

	// Implementation of IPageModule

	public function getHtml() {
		$this->view->setPath(DIR_PLUGIN . 'Chatbot');
		$this->view->setTemplate('Content/ChatbotPageModule.php');
		$defaults = ['service' => 'chatbotservice.php'];
		foreach (array_merge($defaults, $this->data) as $tag => $content) $this->view->assign($tag, $content);
		return $this->view->loadTemplate();
	}

	// Implementation of ISchemaProvider

	public function getSchema(): array {
		$schema = [
			'$schema' => 'https://json-schema.org/draft-2020-12/schema',
			'type' => 'object',
			'properties' => [
				'service' => [
					'type' => 'string',
					'description' => 'Service URL',
					'maxLength' => 200,
					'default' => 'chatbotservice.php',
				],
			],
			'required' => ['image'],
		];
		return $schema;
	}
}
