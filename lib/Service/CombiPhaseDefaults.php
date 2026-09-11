<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

class CombiPhaseDefaults
{
	/**
	 * Returns the default lifecycle phases for Combi projects.
	 * Aligned with customer workflow specification using real existing card titles.
	 *
	 * @return array<string, array{
	 *     order: int,
	 *     name: string,
	 *     category: string,
	 *     color: string,
	 *     milestone: string,
	 *     cards: string[],
	 *     customTasks: array<int, array{name: string, durationDays: int}>
	 * }>
	 */
	public static function getPhases(): array
	{
		$cardsByKey = [];
		foreach (array_merge(
			ProjectTypeDeckDefaults::getNextPriorityCards(ProjectTypeDeckDefaults::TYPE_COMBI),
			ProjectTypeDeckDefaults::getProcessStepCards(ProjectTypeDeckDefaults::TYPE_COMBI),
		) as $card) {
			$cardsByKey[$card['key']] = $card;
		}
		$currentCardTitles = array_map(
			static fn (string $key): string => $cardsByKey[$key]['title'],
			ProjectTypeDeckDefaults::getDefaultCardKeysInTimelineOrder(ProjectTypeDeckDefaults::TYPE_COMBI),
		);

		return [
			'initiation' => [
				'order' => 2,
				'name' => 'Initiation Phase',
				'category' => 'initiation',
				'color' => '#10b981',
				'milestone' => 'Initiation ready',
				'cards' => $currentCardTitles,
				'customTasks' => [],
			],
			'preparation' => [
				'order' => 3,
				'name' => 'Preparation Phase',
				'category' => 'preparation',
				'color' => '#f59e0b',
				'milestone' => 'Start construction',
				'cards' => [],
				'customTasks' => [],
			],
			'execution' => [
				'order' => 4,
				'name' => 'Execution / Construction',
				'category' => 'execution',
				'color' => '#3b82f6',
				'milestone' => 'End construction',
				'cards' => [],
				'customTasks' => [],
			],
			'handover' => [
				'order' => 5,
				'name' => 'Handover Phase',
				'category' => 'handover',
				'color' => '#8b5cf6',
				'milestone' => 'Project complete',
				'cards' => [],
				'customTasks' => [],
			],
		];
	}

	/**
	 * Default fallback dependencies (Finish-to-Start) by card title.
	 * Key: Successor card title.
	 * Value: Array of Predecessor card titles.
	 *
	 * @return array<string, string[]>
	 */
	public static function getDefaultCardDependencies(): array
	{
		$templates = array_merge(
			ProjectTypeDeckDefaults::getNextPriorityCards(ProjectTypeDeckDefaults::TYPE_COMBI),
			ProjectTypeDeckDefaults::getProcessStepCards(ProjectTypeDeckDefaults::TYPE_COMBI),
		);
		$titlesByKey = [];
		foreach ($templates as $template) {
			$titlesByKey[$template['key']] = $template['title'];
		}

		$dependencies = [];
		foreach (ProjectTypeDeckDefaults::getDefaultDependencyKeys(ProjectTypeDeckDefaults::TYPE_COMBI) as $successorKey => $predecessorKeys) {
			$dependencies[$titlesByKey[$successorKey]] = array_map(
				static fn (string $key): string => $titlesByKey[$key],
				$predecessorKeys,
			);
		}

		return $dependencies;
	}

	public static function findPhaseKeyForCardTitle(string $title): ?string
	{
		$trimmed = trim(strtolower($title));
		foreach (self::getPhases() as $key => $phase) {
			foreach ($phase['cards'] as $cardTitle) {
				if (trim(strtolower($cardTitle)) === $trimmed) {
					return $key;
				}
			}
		}
		return null;
	}
}
