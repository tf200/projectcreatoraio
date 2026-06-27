<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPolicyMembership extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $roleId = null;
	protected ?string $participantType = null;
	protected ?string $participantId = null;

	public function __construct() {
		$this->addType('roleId', Types::BIGINT);
		$this->addType('participantType', Types::STRING);
		$this->addType('participantId', Types::STRING);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'roleId' => $this->roleId,
			'participantType' => $this->participantType,
			'participantId' => $this->participantId,
		];
	}
}
