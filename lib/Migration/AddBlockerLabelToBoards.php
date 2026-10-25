<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Migration;

use OCA\ProjectCreatorAIO\Service\BlockerLabel;
use OCP\IDBConnection;
use OCP\Migration\IOutput;
use OCP\Migration\IRepairStep;

/**
 * Gives every project board the "Blokkade" label. Safe to run on every
 * upgrade: boards that have it are left alone.
 */
class AddBlockerLabelToBoards implements IRepairStep
{
	public function __construct(
		private readonly IDBConnection $db,
	) {
	}

	public function getName(): string
	{
		return 'Add the Blokkade label to project boards';
	}

	public function run(IOutput $output): void
	{
		if (!$this->db->tableExists('custom_projects') || !$this->db->tableExists('deck_labels')) {
			return;
		}

		$qb = $this->db->getQueryBuilder();
		$qb->select('board_id')
			->from('custom_projects')
			->where($qb->expr()->isNotNull('board_id'));
		$result = $qb->executeQuery();
		$boardIds = [];
		while (($row = $result->fetch()) !== false) {
			$boardId = (int)$row['board_id'];
			if ($boardId > 0) {
				$boardIds[$boardId] = true;
			}
		}
		$result->closeCursor();

		$label = new BlockerLabel($this->db);
		$added = 0;
		foreach (array_keys($boardIds) as $boardId) {
			if ($label->ensure($boardId)) {
				$added++;
			}
		}
		$output->info(sprintf('Blokkade label added to %d of %d project boards', $added, count($boardIds)));
	}
}
