<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class CardPolicyRole extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $cardPolicyId = null;
	protected ?string $action = null;
	protected ?int $roleId = null;

	public function __construct() {
		$this->addType('cardPolicyId', Types::BIGINT);
		$this->addType('action', Types::STRING);
		$this->addType('roleId', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'cardPolicyId' => $this->cardPolicyId,
			'action' => $this->action,
			'roleId' => $this->roleId,
		];
	}
}
