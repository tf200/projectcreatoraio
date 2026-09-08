<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Db;

use DateTime;
use JsonSerializable;
use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getName()
 * @method void setName(string $name)
 * @method string getCategory()
 * @method void setCategory(string $category)
 * @method int getOrderIndex()
 * @method void setOrderIndex(int $orderIndex)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method DateTime|null getCreatedAt()
 * @method void setCreatedAt(?DateTime $createdAt)
 * @method DateTime|null getUpdatedAt()
 * @method void setUpdatedAt(?DateTime $updatedAt)
 */
class TimelinePhase extends Entity implements JsonSerializable
{
	protected $projectId;
	protected $name;
	protected $category;
	protected $orderIndex;
	protected $color;
	protected $createdAt;
	protected $updatedAt;

	public function __construct()
	{
		$this->addType('projectId', Types::INTEGER);
		$this->addType('orderIndex', Types::INTEGER);
		$this->addType('name', Types::STRING);
		$this->addType('category', Types::STRING);
		$this->addType('color', Types::STRING);
		$this->addType('createdAt', Types::DATETIME);
		$this->addType('updatedAt', Types::DATETIME);
	}

	public function jsonSerialize(): array
	{
		return [
			'id' => (int) $this->getId(),
			'projectId' => (int) $this->getProjectId(),
			'name' => (string) ($this->getName() ?? ''),
			'category' => (string) ($this->getCategory() ?? 'custom'),
			'orderIndex' => (int) ($this->getOrderIndex() ?? 0),
			'color' => (string) ($this->getColor() ?? '#3b82f6'),
			'createdAt' => $this->getCreatedAt() ? $this->getCreatedAt()->format('Y-m-d H:i:s') : null,
			'updatedAt' => $this->getUpdatedAt() ? $this->getUpdatedAt()->format('Y-m-d H:i:s') : null,
		];
	}
}
