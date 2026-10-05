<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * A saved What-If scenario: a name and the list of changes it lays over the live plan.
 *
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getChanges()
 * @method void setChanges(string $changes)
 * @method string getCreatedBy()
 * @method void setCreatedBy(string $createdBy)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 */
class TimelineScenario extends Entity implements JsonSerializable
{
	protected ?int $projectId = null;
	protected ?string $name = null;
	protected ?string $changes = null;
	protected ?string $createdBy = null;
	protected ?DateTime $createdAt = null;
	protected ?DateTime $updatedAt = null;

	public function __construct()
	{
		$this->addType('projectId', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('changes', Types::TEXT);
		$this->addType('createdBy', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	/** @return array<int, array<string, mixed>> */
	public function getChangeList(): array
	{
		$changes = json_decode((string)$this->changes, true);
		return is_array($changes) ? $changes : [];
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => $this->id,
			'projectId' => $this->projectId,
			'name' => $this->name,
			'changes' => $this->getChangeList(),
			'createdBy' => $this->createdBy,
			'createdAt' => $this->createdAt?->format(DateTime::ATOM),
			'updatedAt' => $this->updatedAt?->format(DateTime::ATOM),
		];
	}
}
