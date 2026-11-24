<?php

namespace OCA\ProjectCreatorAIO\Db;

use OCA\ProjectCreatorAIO\Service\DefaultLanguageL10n;
use OCP\IDBConnection;
use OCP\IL10N;
use OCP\AppFramework\Db\QBMapper;
use OCP\DB\QueryBuilder\IQueryBuilder;
use DateTime;
use OCP\AppFramework\Db\DoesNotExistException;

class TimelineItemMapper extends QBMapper
{
    public const TABLE_NAME = "project_timeline_items";

    private IL10N $l;

    public function __construct(IDBConnection $db, ?IL10N $l = null)
    {
        parent::__construct($db, self::TABLE_NAME, TimelineItem::class);
        $this->l = $l ?? DefaultLanguageL10n::userL10n();
    }

    /**
     * Find all timeline items for a project
     * @param int $projectId
     * @return TimelineItem[]
     */
    public function findByProject(int $projectId): array
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->orderBy('order_index', 'ASC');

        return $this->findEntities($qb);
    }

    /**
     * Find a single timeline item by ID
     * @param int $id
     * @return TimelineItem|null
     */
    public function find(int $id): ?TimelineItem
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('id', $qb->createNamedParameter($id, IQueryBuilder::PARAM_INT)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Create a new timeline item
     */
    public function createItem(
        int $projectId,
        string $label,
        ?string $startDate,
        ?string $endDate,
        string $color,
        int $orderIndex,
        ?string $systemKey = null,
        string $itemType = 'phase'
    ): TimelineItem {
        $item = new TimelineItem();
        $item->setProjectId($projectId);
        $item->setLabel($label);

        $itemType = trim((string) $itemType);
        $item->setItemType($itemType !== '' ? $itemType : 'phase');

        if ($startDate !== null) {
            $item->setStartDate(new DateTime($startDate));
        }
        if ($endDate !== null) {
            $item->setEndDate(new DateTime($endDate));
        }

        $item->setColor($color);
        $item->setOrderIndex($orderIndex);

        if ($systemKey !== null && trim($systemKey) !== '') {
            $item->setSystemKey($systemKey);
        }

        $now = new DateTime();
        $item->setCreatedAt($now);
        $item->setUpdatedAt($now);

        return $this->insert($item);
    }

    public function findByProjectAndSystemKey(int $projectId, string $systemKey): ?TimelineItem
    {
        $systemKey = trim($systemKey);
        if ($systemKey === '') {
            return null;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->select('*')
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('system_key', $qb->createNamedParameter($systemKey)));

        try {
            return $this->findEntity($qb);
        } catch (DoesNotExistException $e) {
            return null;
        }
    }

    /**
     * Update an existing timeline item
     */
    public function updateItem(TimelineItem $item): TimelineItem
    {
        $item->setUpdatedAt(new DateTime());
        return $this->update($item);
    }

    /**
     * Delete all timeline items for a project
     * @param int $projectId
     */
    public function deleteByProject(int $projectId): void
    {
        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));
        $qb->executeStatement();
    }

    /**
     * Get the next order index for a project
     */
    public function getNextOrderIndex(int $projectId): int
    {
        $qb = $this->db->getQueryBuilder();
        $qb->select($qb->func()->max('order_index'))
            ->from($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)));

        $result = $qb->executeQuery();
        $row = $result->fetch();
        $result->closeCursor();

        $max = $row['MAX(order_index)'] ?? null;
        if ($max === null) {
            return 0;
        }

        return ((int) $max) + 1;
    }

    /**
     * Reorder all items for a project in one operation.
     *
     * @param int $projectId
     * @param int[] $orderedIds The full, desired order of timeline item IDs.
     * @return TimelineItem[] Updated items, ordered by order_index.
     */
    public function reorderItems(int $projectId, array $orderedIds): array
    {
        $orderedIds = array_values(array_unique(array_map('intval', $orderedIds)));
        $orderedIds = array_values(array_filter($orderedIds, static fn (int $id) => $id > 0));

        $items = $this->findByProject($projectId);
        $itemsById = [];
        $expectedIds = [];

        foreach ($items as $item) {
            $itemsById[$item->getId()] = $item;
            $expectedIds[] = (int) $item->getId();
        }

        sort($expectedIds);
        $givenIds = $orderedIds;
        sort($givenIds);

        if ($givenIds !== $expectedIds) {
            throw new \InvalidArgumentException($this->l->t('Invalid reorder payload'));
        }

        $orderIndex = 0;

        $this->db->beginTransaction();
        try {
            foreach ($orderedIds as $id) {
                $item = $itemsById[$id] ?? null;
                if ($item === null) {
                    throw new \InvalidArgumentException($this->l->t('Invalid reorder payload'));
                }

                $item->setOrderIndex($orderIndex);
                $this->updateItem($item);
                $orderIndex++;
            }
            $this->db->commit();
        } catch (\Throwable $e) {
            $this->db->rollBack();
            throw $e;
        }

        return $this->findByProject($projectId);
    }

    public function deleteByProjectAndSystemKey(int $projectId, string $systemKey): void
    {
        $systemKey = trim($systemKey);
        if ($systemKey === '') {
            return;
        }

        $qb = $this->db->getQueryBuilder();
        $qb->delete($this->getTableName())
            ->where($qb->expr()->eq('project_id', $qb->createNamedParameter($projectId, IQueryBuilder::PARAM_INT)))
            ->andWhere($qb->expr()->eq('system_key', $qb->createNamedParameter($systemKey, IQueryBuilder::PARAM_STR)));
        $qb->executeStatement();
    }
}
