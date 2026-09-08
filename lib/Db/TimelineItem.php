<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCP\AppFramework\Db\Entity;
use OCP\DB\Types;
use DateTime;
use JsonSerializable;

/**
 * @method int getId()
 * @method void setId(int $id)
 * @method int getProjectId()
 * @method void setProjectId(int $projectId)
 * @method string getLabel()
 * @method void setLabel(string $label)
 * @method DateTime|null getStartDate()
 * @method void setStartDate(?DateTime $startDate)
 * @method DateTime|null getEndDate()
 * @method void setEndDate(?DateTime $endDate)
 * @method string getColor()
 * @method void setColor(string $color)
 * @method string|null getItemType()
 * @method void setItemType(?string $itemType)
 * @method int getOrderIndex()
 * @method void setOrderIndex(int $orderIndex)
 * @method DateTime getCreatedAt()
 * @method void setCreatedAt(DateTime $createdAt)
 * @method DateTime getUpdatedAt()
 * @method void setUpdatedAt(DateTime $updatedAt)
 * @method string|null getSystemKey()
 * @method void setSystemKey(?string $systemKey)
 * @method int|null getPhaseId()
 * @method void setPhaseId(?int $phaseId)
 * @method int|null getDeckCardId()
 * @method void setDeckCardId(?int $deckCardId)
 * @method int|null getDurationDays()
 * @method void setDurationDays(?int $durationDays)
 * @method string|null getStatus()
 * @method void setStatus(?string $status)
 * @method DateTime|null getPlannedEndDate()
 * @method void setPlannedEndDate(?DateTime $plannedEndDate)
 */
class TimelineItem extends Entity implements JsonSerializable
{
    protected $projectId;
    protected $label;
    protected $startDate;
    protected $endDate;
    protected $color;
    protected $itemType;
    protected $orderIndex;
    protected $systemKey;
    protected $phaseId;
    protected $deckCardId;
    protected $durationDays;
    protected $status;
    protected $plannedEndDate;
    protected $createdAt;
    protected $updatedAt;

    public function __construct()
    {
        $this->addType('projectId', Types::INTEGER);
        $this->addType('orderIndex', Types::INTEGER);
        $this->addType('startDate', Types::DATE);
        $this->addType('endDate', Types::DATE);
        $this->addType('systemKey', Types::STRING);
        $this->addType('itemType', Types::STRING);
        $this->addType('phaseId', Types::INTEGER);
        $this->addType('deckCardId', Types::INTEGER);
        $this->addType('durationDays', Types::INTEGER);
        $this->addType('status', Types::STRING);
        $this->addType('plannedEndDate', Types::DATE);
        $this->addType('createdAt', Types::DATETIME);
        $this->addType('updatedAt', Types::DATETIME);
    }


    public function jsonSerialize(): array
    {
        $itemType = (string) ($this->getItemType() ?? '');
        $itemType = trim($itemType);
        if ($itemType === '') {
            $itemType = 'phase';
        }

        return [
            'id' => $this->getId(),
            'projectId' => $this->getProjectId(),
            'phaseId' => $this->getPhaseId() !== null ? (int)$this->getPhaseId() : null,
            'deckCardId' => $this->getDeckCardId() !== null ? (int)$this->getDeckCardId() : null,
            'label' => $this->getLabel(),
            'startDate' => $this->startDate instanceof DateTime
                ? $this->startDate->format('Y-m-d')
                : $this->startDate,
            'endDate' => $this->endDate instanceof DateTime
                ? $this->endDate->format('Y-m-d')
                : $this->endDate,
            'plannedEndDate' => $this->plannedEndDate instanceof DateTime
                ? $this->plannedEndDate->format('Y-m-d')
                : $this->plannedEndDate,
            'durationDays' => $this->getDurationDays() !== null ? (int)$this->getDurationDays() : null,
            'status' => (string) ($this->getStatus() ?? 'not_started'),
            'color' => $this->getColor(),
            'itemType' => $itemType,
            'orderIndex' => $this->getOrderIndex(),
            'systemKey' => $this->getSystemKey(),
            'createdAt' => $this->createdAt instanceof DateTime
                ? $this->createdAt->format('Y-m-d H:i:s')
                : $this->createdAt,
            'updatedAt' => $this->updatedAt instanceof DateTime
                ? $this->updatedAt->format('Y-m-d H:i:s')
                : $this->updatedAt,
        ];
    }
}
