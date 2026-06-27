<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPolicyRole extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $boardId = null;
	protected ?string $roleKey = null;
	protected ?string $roleName = null;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('roleKey', Types::STRING);
		$this->addType('roleName', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'boardId' => $this->boardId,
			'roleKey' => $this->roleKey,
			'name' => $this->roleName,
			'color' => '#0082c9', // Nextcloud Blue
		];
	}
}
