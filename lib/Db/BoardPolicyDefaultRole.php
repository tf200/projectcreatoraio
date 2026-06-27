<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPolicyDefaultRole extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $boardId = null;
	protected ?string $action = null;
	protected ?int $roleId = null;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('action', Types::STRING);
		$this->addType('roleId', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'boardId' => $this->boardId,
			'action' => $this->action,
			'roleId' => $this->roleId,
		];
	}
}
