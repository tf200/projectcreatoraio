<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class ProjectActivityEvent extends Entity implements JsonSerializable {
	public $id;

	protected ?int $projectId = null;
	protected ?string $actorUid = null;
	protected ?string $actorDisplayName = null;
	protected ?string $eventType = null;
	protected ?string $source = null;
	protected ?string $payloadJson = null;
	protected ?DateTime $occurredAt = null;

	public function __construct() {
		$this->addType('projectId', Types::INTEGER);
		$this->addType('actorUid', Types::STRING);
		$this->addType('actorDisplayName', Types::STRING);
		$this->addType('eventType', Types::STRING);
		$this->addType('source', Types::STRING);
		$this->addType('payloadJson', Types::TEXT);
		$this->addType('occurredAt', Types::DATETIME);
	}

	public function setPayloadArray(array $payload): void {
		$json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
		$this->setPayloadJson($json === false ? '{}' : $json);
	}

	/**
	 * @return array<string, mixed>
	 */
	public function getPayloadArray(): array {
		$payloadJson = (string) ($this->getPayloadJson() ?? '');
		if ($payloadJson === '') {
			return [];
		}

		$decoded = json_decode($payloadJson, true);
		return is_array($decoded) ? $decoded : [];
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'projectId' => $this->projectId,
			'actorUid' => $this->actorUid,
			'actorDisplayName' => $this->actorDisplayName,
			'eventType' => $this->eventType,
			'source' => $this->source,
			'payload' => $this->getPayloadArray(),
			'occurredAt' => $this->occurredAt?->format('c'),
		];
	}
}
