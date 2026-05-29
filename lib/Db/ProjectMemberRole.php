<?php
declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

class ProjectMemberRole extends Entity implements \JsonSerializable {
	public int $id;
	protected int $projectId;
	protected string $userId;
	protected string $drasciRole;
	protected DateTime $createdAt;
	protected DateTime $updatedAt;

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
