<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class CardPolicyOverride extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $cardPolicyId = null;
	protected ?string $action = null;

	public function __construct() {
		$this->addType('cardPolicyId', Types::BIGINT);
		$this->addType('action', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'cardPolicyId' => $this->cardPolicyId,
			'action' => $this->action,
		];
	}
}
