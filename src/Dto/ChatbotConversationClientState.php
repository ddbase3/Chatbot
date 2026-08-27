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

namespace Chatbot\Dto;

use AssistantFoundation\Dto\AgentConversationState;
use AssistantFoundation\Dto\AgentInteractionRequest;
use AssistantFoundation\Dto\AgentInteractionResponse;
use AssistantFoundation\Dto\AgentSuspensionResolution;
use AssistantFoundation\Dto\AgentSuspensionState;

/**
 * Client-facing conversation state with an optional unsaved draft.
 */
final class ChatbotConversationClientState {

	/** @param array<int,AgentSuspensionState> $suspensions */
	public function __construct(
		private readonly AgentConversationState $state,
		private readonly ?ChatbotConversationDraft $draft = null,
		private readonly ?AgentSuspensionState $pendingSuspension = null,
		private readonly array $suspensions = []
	) {}

	public function getState(): AgentConversationState {
		return $this->state;
	}

	public function getDraft(): ?ChatbotConversationDraft {
		return $this->draft;
	}

	public function getPendingSuspension(): ?AgentSuspensionState {
		return $this->pendingSuspension;
	}

	/** @return array<int,AgentSuspensionState> */
	public function getSuspensions(): array {
		return $this->suspensions;
	}

	/** @return array<string,mixed> */
	public function toArray(): array {
		$pending = $this->pendingSuspension instanceof AgentSuspensionState
			? $this->toClientSuspension($this->pendingSuspension)
			: null;
		$interactions = [];
		foreach ($this->suspensions as $suspension) {
			if ($suspension instanceof AgentSuspensionState) {
				$interactions[] = $this->toClientSuspension($suspension);
			}
		}

		return array_replace(
			$this->state->toArray(),
			[
				'draft' => $this->draft?->toClientArray(),
				'pending_interaction' => $pending,
				'interactions' => $interactions
			]
		);
	}

	/** @return array<string,mixed> */
	private function toClientSuspension(AgentSuspensionState $suspension): array {
		$resolution = $suspension->getResolution();

		return [
			'id' => $suspension->getId(),
			'lifecycle' => $suspension->getLifecycle(),
			'status' => $suspension->getStatus(),
			'resume_handle' => $suspension->isSuspended() ? $suspension->getResumeHandle() : '',
			'created_at' => $suspension->getCreatedAt(),
			'expires_at' => $suspension->getExpiresAt(),
			'interaction_requests' => array_values(array_filter(array_map(
				fn(mixed $request): ?array => $this->toClientRequest($request),
				$suspension->getInteractionRequests()
			))),
			'resolution' => $resolution instanceof AgentSuspensionResolution
				? $this->toClientResolution($resolution)
				: null
		];
	}

	/** @return array<string,mixed>|null */
	private function toClientRequest(mixed $request): ?array {
		if ($request instanceof AgentInteractionRequest) {
			return [
				'id' => $request->getId(),
				'kind' => $request->getKind(),
				'title' => $request->getTitle(),
				'message' => $request->getMessage(),
				'summary' => $request->getSummary(),
				'risk' => $request->getRisk()
			];
		}
		if (!is_array($request)) {
			return null;
		}

		return [
			'id' => trim((string)($request['id'] ?? '')),
			'kind' => trim((string)($request['kind'] ?? '')),
			'title' => trim((string)($request['title'] ?? '')),
			'message' => trim((string)($request['message'] ?? '')),
			'summary' => is_array($request['summary'] ?? null) ? $request['summary'] : [],
			'risk' => trim((string)($request['risk'] ?? ''))
		];
	}

	/** @return array<string,mixed> */
	private function toClientResolution(AgentSuspensionResolution $resolution): array {
		return [
			'outcome' => $resolution->getOutcome(),
			'source' => $resolution->getSource(),
			'resolved_at' => $resolution->getResolvedAt(),
			'responses' => array_map(
				static fn(AgentInteractionResponse $response): array => [
					'request_id' => $response->getRequestId(),
					'decision' => $response->getDecision()
				],
				$resolution->getResponses()
			)
		];
	}
}
