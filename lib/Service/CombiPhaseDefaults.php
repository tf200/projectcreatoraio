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
		return [
			'initiation' => [
				'order' => 2,
				'name' => 'Initiation Phase',
				'category' => 'initiation',
				'color' => '#10b981',
				'milestone' => 'Initiation ready',
				'cards' => [
					'Intakeformulier',
					'Piekvermogensformulier',
					'Quickscan',
					'Situatie tekening',
				],
				'customTasks' => [],
			],
			'preparation' => [
				'order' => 3,
				'name' => 'Preparation Phase',
				'category' => 'preparation',
				'color' => '#f59e0b',
				'milestone' => 'Start construction',
				'cards' => [
					'AVP',
					'VO',
					'Intake inplannen & hosten',
					'Intakeverslag',
					'DO',
					// Conditional Set 1 (Hoogbouw)
					'Hoogbouwoverleg inplannen',
					'VO inpandige tekeningen',
					'Verslag inpandig overleg',
					'DO inpandige tekeningen',
					'Blokkenschema',
					// Conditional Set 2 (Bodem / Grond)
					'Aanvraag particuliere grond',
					'Bodemrapport',
					'Saneringsevaluatierapport',
					'Zakelijkrecht',
					// Standalone tracks
					'Huisnummerbesluit',
					'Garantie overeenkomst',
				],
				'customTasks' => [],
			],
			'execution' => [
				'order' => 4,
				'name' => 'Execution / Construction',
				'category' => 'execution',
				'color' => '#3b82f6',
				'milestone' => 'End construction',
				'cards' => [],
				'customTasks' => [
					['name' => 'Earthworks', 'durationDays' => 28],
					['name' => 'Cable installation', 'durationDays' => 42],
					['name' => 'Reinstatement works', 'durationDays' => 21],
				],
			],
			'handover' => [
				'order' => 5,
				'name' => 'Handover Phase',
				'category' => 'handover',
				'color' => '#8b5cf6',
				'milestone' => 'Project complete',
				'cards' => [],
				'customTasks' => [
					['name' => 'Administrative handover', 'durationDays' => 14],
				],
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
		return [
			'Piekvermogensformulier' => ['Intakeformulier'],
			'Quickscan' => ['Intakeformulier'],
			'Situatie tekening' => ['Quickscan'],
			'AVP' => ['Piekvermogensformulier'],
			'VO' => ['Situatie tekening', 'AVP'],
			'Intake inplannen & hosten' => ['VO'],
			'Intakeverslag' => ['Intake inplannen & hosten'],
			'DO' => ['VO', 'Intakeverslag'],
			// Conditional Set 1 (Hoogbouw) chain
			'VO inpandige tekeningen' => ['Hoogbouwoverleg inplannen'],
			'Verslag inpandig overleg' => ['VO inpandige tekeningen'],
			'DO inpandige tekeningen' => ['Verslag inpandig overleg'],
			'Blokkenschema' => ['DO inpandige tekeningen'],
			// Conditional Set 2 (Bodem) chain
			'Bodemrapport' => ['Aanvraag particuliere grond'],
			'Saneringsevaluatierapport' => ['Bodemrapport'],
			'Zakelijkrecht' => ['Saneringsevaluatierapport'],
		];
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
