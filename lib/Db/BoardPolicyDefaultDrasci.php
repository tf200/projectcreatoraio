<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPolicyDefaultDrasci extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $boardId = null;
	protected ?string $action = null;
	protected ?string $drasciRole = null;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('action', Types::STRING);
		$this->addType('drasciRole', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'boardId' => $this->boardId,
			'action' => $this->action,
			'drasciRole' => $this->drasciRole,
		];
	}
}
