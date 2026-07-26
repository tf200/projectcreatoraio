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
	protected int $policyVersion = 1;
	protected int $revision = 0;

	public function __construct() {
		$this->addType('boardId', Types::BIGINT);
		$this->addType('permissionMode', Types::STRING);
		$this->addType('approvedStackId', Types::BIGINT);
		$this->addType('doneStackId', Types::BIGINT);
		$this->addType('policyVersion', Types::INTEGER);
		$this->addType('revision', Types::BIGINT);
	}

	public function jsonSerialize(): array {
		return [
			'id' => $this->id,
			'boardId' => $this->boardId,
			'permissionMode' => $this->permissionMode,
			'approvedStackId' => $this->approvedStackId,
			'doneStackId' => $this->doneStackId,
			'policyVersion' => $this->policyVersion,
			'revision' => $this->revision,
		];
	}
}
