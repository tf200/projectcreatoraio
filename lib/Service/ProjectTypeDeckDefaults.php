<?php

namespace OCA\ProjectCreatorAIO\Service;

class ProjectTypeDeckDefaults
{
	public const TYPE_COMBI = 0;

	/** @return string[] */
	public static function getConditionalSet1Titles(): array
	{
		return [
			'Hoogbouwoverleg inplannen',
			'VO inpandige tekeningen',
			'DO inpandige tekeningen',
			'Verslag inpandig overleg',
			'Blokkenschema',
		];
	}

	/** @return string[] */
	public static function getConditionalSet2Titles(): array
	{
		return [
			'Aanvraag particuliere grond',
			'Bodemrapport',
			'Saneringsevaluatierapport',
			'Zakelijkrecht',
		];
	}

	/**
	 * Map canonical card title to accepted aliases on existing boards.
	 *
	 * @return array<string, string[]>
	 */
	public static function getCardTitleAliases(): array
	{
		return [
			'Blokkenschema' => ['Blokkenschema', 'Blokkenshema'],
		];
	}

	/** @return array<int, array{key: string, title: string, important: bool}> */
	public static function getNextPriorityCards(int $projectType): array
	{
		if ($projectType !== self::TYPE_COMBI) {
			return [];
		}

		return [
			['key' => 'combi.peak_power_form', 'title' => 'Piekvermogensformulier', 'important' => true],
			['key' => 'combi.situation_drawing', 'title' => 'Situatie tekening', 'important' => true],
			['key' => 'combi.intake_form', 'title' => 'Intakeformulier', 'important' => true],
			['key' => 'combi.quickscan', 'title' => 'Quickscan', 'important' => true],
			['key' => 'combi.avp', 'title' => 'AVP', 'important' => true],
		];
	}

	/** @return array<int, array{key: string, title: string, important: bool}> */
	public static function getProcessStepCards(int $projectType): array
	{
		if ($projectType !== self::TYPE_COMBI) {
			return [];
		}

		return [
			['key' => 'combi.guarantee_agreement', 'title' => 'Garantie overeenkomst', 'important' => false],
			['key' => 'combi.vo', 'title' => 'VO', 'important' => true],
			['key' => 'combi.do', 'title' => 'DO', 'important' => true],
			['key' => 'combi.schedule_intake', 'title' => 'Intake inplannen & hosten', 'important' => false],
			['key' => 'combi.intake_report', 'title' => 'Intakeverslag', 'important' => false],
			['key' => 'combi.house_number_decision', 'title' => 'Huisnummerbesluit', 'important' => true],
			['key' => 'combi.schedule_high_rise_consultation', 'title' => 'Hoogbouwoverleg inplannen', 'important' => false],
			['key' => 'combi.vo_internal_drawings', 'title' => 'VO inpandige tekeningen', 'important' => false],
			['key' => 'combi.do_internal_drawings', 'title' => 'DO inpandige tekeningen', 'important' => false],
			['key' => 'combi.internal_consultation_report', 'title' => 'Verslag inpandig overleg', 'important' => true],
			['key' => 'combi.block_diagram', 'title' => 'Blokkenschema', 'important' => false],
			['key' => 'combi.private_land_application', 'title' => 'Aanvraag particuliere grond', 'important' => false],
			['key' => 'combi.soil_report', 'title' => 'Bodemrapport', 'important' => true],
			['key' => 'combi.remediation_evaluation_report', 'title' => 'Saneringsevaluatierapport', 'important' => false],
			['key' => 'combi.property_right', 'title' => 'Zakelijkrecht', 'important' => false],
		];
	}

	/** @return string[] */
	public static function getRequiredNextPriorityTitles(int $projectType): array
	{
		$cards = self::getNextPriorityCards($projectType);
		return array_values(array_map(static fn (array $item) => (string) ($item['title'] ?? ''), $cards));
	}

	/**
	 * All default card titles marked as important for the given project type.
	 *
	 * @return string[]
	 */
	public static function getImportantTitles(int $projectType): array
	{
		$cards = array_merge(self::getNextPriorityCards($projectType), self::getProcessStepCards($projectType));
		$out = [];
		foreach ($cards as $card) {
			$title = trim((string) ($card['title'] ?? ''));
			if ($title === '') {
				continue;
			}
			if (!((bool) ($card['important'] ?? false))) {
				continue;
			}
			$out[] = $title;
		}
		return array_values(array_unique($out));
	}

	/**
	 * Important titles that are currently visible, based on enabled conditional sets.
	 *
	 * For Combi projects, set membership is defined by getConditionalSet1Titles/getConditionalSet2Titles.
	 *
	 * @param int[] $enabledSets
	 * @return string[]
	 */
	public static function getVisibleImportantTitles(int $projectType, array $enabledSets): array
	{
		$titles = self::getImportantTitles($projectType);
		if ($titles === []) {
			return [];
		}

		if ($projectType !== self::TYPE_COMBI) {
			return $titles;
		}

		$isSet1Enabled = in_array(1, $enabledSets, true);
		$isSet2Enabled = in_array(2, $enabledSets, true);
		$set1Titles = self::getConditionalSet1Titles();
		$set2Titles = self::getConditionalSet2Titles();

		$out = [];
		foreach ($titles as $title) {
			if (in_array($title, $set1Titles, true) && !$isSet1Enabled) {
				continue;
			}
			if (in_array($title, $set2Titles, true) && !$isSet2Enabled) {
				continue;
			}
			$out[] = $title;
		}
		return $out;
	}

	public static function getDefaultPreparationWeeks(int $projectType): int
	{
		return 0;
	}
}
