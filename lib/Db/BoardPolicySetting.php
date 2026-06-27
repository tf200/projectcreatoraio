<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class BoardPolicySetting extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $boardId = null;
	protected ?string $permissionMode = null;
	protected ?int $approvedStackId = null;
	protected ?int $doneStackId = null;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('permissionMode', Types::STRING);
		$this->addType('approvedStackId', Types::BIGINT);
		$this->addType('doneStackId', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'boardId' => $this->boardId,
			'permissionMode' => $this->permissionMode,
			'approvedStackId' => $this->approvedStackId,
			'doneStackId' => $this->doneStackId,
		];
	}
}
