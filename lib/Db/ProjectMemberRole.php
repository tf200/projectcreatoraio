<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class ProjectMemberRole extends Entity implements \JsonSerializable {
	public $id;
	protected ?int $projectId = null;
	protected ?string $userId = null;
	protected ?string $drasciRole = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct() {
		$this->addType('projectId', Types::BIGINT);
		$this->addType('userId', Types::STRING);
		$this->addType('drasciRole', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function jsonSerialize(): array {
		return [
			'id'         => $this->id,
			'projectId'  => $this->projectId,
			'userId'     => $this->userId,
			'drasciRole' => $this->drasciRole,
			'createdAt'  => $this->createdAt,
			'updatedAt'  => $this->updatedAt,
		];
	}
}
