<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPermissionProfile extends Entity implements JsonSerializable {
	protected ?int $organizationId = null;
	protected ?string $creatorUid = null;
	protected ?string $name = null;
	protected int $schemaVersion = 2;
	protected ?string $payloadJson = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('organizationId', Types::BIGINT);
		$this->addType('creatorUid', Types::STRING);
		$this->addType('name', Types::STRING);
		$this->addType('schemaVersion', Types::INTEGER);
		$this->addType('payloadJson', Types::TEXT);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function getPayload(): array {
		$value = json_decode((string)$this->payloadJson, true);
		return is_array($value) ? $value : [];
	}

	public function jsonSerialize(): array {
		return ['id' => $this->getId(), 'organizationId' => $this->organizationId, 'creatorUid' => $this->creatorUid,
			'name' => $this->name, 'schemaVersion' => $this->schemaVersion, 'payload' => $this->getPayload(),
			'createdAt' => $this->createdAt?->format(DATE_ATOM), 'updatedAt' => $this->updatedAt?->format(DATE_ATOM)];
	}
}
