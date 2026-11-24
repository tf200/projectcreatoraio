<?php

namespace OCA\ProjectCreatorAIO\Service;

use OCP\IL10N;

/**
 * Default Deck cards per project type.
 *
 * The 'title' of a card template is its canonical title: logic matches and groups
 * cards by it. A new card gets the title in the server default language
 * (translateTitle); existing boards keep the titles they have. Code that
 * recognises default cards on a board must accept every variant (titleVariants /
 * canonicalTitle), never only the canonical one.
 */
class ProjectTypeDeckDefaults
{
	public const TYPE_COMBI = 0;

	/**
	 * Canonical card title => title in the language of $l.
	 *
	 * @return array<string, string>
	 */
	public static function titleTranslations(IL10N $l): array
	{
		return [
			'Piekvermogensformulier' => $l->t('Piekvermogensformulier'),
			'Situatie tekening' => $l->t('Situatie tekening'),
			'Intakeformulier' => $l->t('Intakeformulier'),
			'Quickscan' => $l->t('Quickscan'),
			'AVP' => $l->t('AVP'),
			'Garantie overeenkomst' => $l->t('Garantie overeenkomst'),
			'VO' => $l->t('VO'),
			'DO' => $l->t('DO'),
			'Intake inplannen & hosten' => $l->t('Intake inplannen & hosten'),
			'Intakeverslag' => $l->t('Intakeverslag'),
			'Huisnummerbesluit' => $l->t('Huisnummerbesluit'),
			'Hoogbouwoverleg inplannen' => $l->t('Hoogbouwoverleg inplannen'),
			'VO inpandige tekeningen' => $l->t('VO inpandige tekeningen'),
			'DO inpandige tekeningen' => $l->t('DO inpandige tekeningen'),
			'Verslag inpandig overleg' => $l->t('Verslag inpandig overleg'),
			'Blokkenschema' => $l->t('Blokkenschema'),
			'Aanvraag particuliere grond' => $l->t('Aanvraag particuliere grond'),
			'Bodemrapport' => $l->t('Bodemrapport'),
			'Saneringsevaluatierapport' => $l->t('Saneringsevaluatierapport'),
			'Zakelijkrecht' => $l->t('Zakelijkrecht'),
		];
	}

	/** The title a new card gets, in the language of $l (use the server default language). */
	public static function translateTitle(string $canonicalTitle, IL10N $l): string
	{
		return self::titleTranslations($l)[$canonicalTitle] ?? $canonicalTitle;
	}

	/**
	 * Every title a default card may carry on an existing board: the canonical
	 * title, its legacy aliases and its translations.
	 *
	 * @param IL10N[] $translators
	 * @return string[]
	 */
	public static function titleVariants(string $canonicalTitle, array $translators): array
	{
		$aliases = self::getCardTitleAliases();
		$variants = $aliases[$canonicalTitle] ?? [];
		$variants[] = $canonicalTitle;
		foreach ($translators as $l) {
			$variants[] = self::translateTitle($canonicalTitle, $l);
		}
		return array_values(array_unique(array_filter(array_map('trim', $variants), static fn (string $v): bool => $v !== '')));
	}

	/**
	 * Lower-cased variant title => canonical title, for all default cards.
	 *
	 * @param IL10N[] $translators
	 * @return array<string, string>
	 */
	public static function canonicalTitleMap(array $translators): array
	{
		$map = [];
		$templates = array_merge(self::getNextPriorityCards(self::TYPE_COMBI), self::getProcessStepCards(self::TYPE_COMBI));
		foreach (array_unique(array_column($templates, 'title')) as $canonicalTitle) {
			foreach (self::titleVariants($canonicalTitle, $translators) as $variant) {
				$map[mb_strtolower($variant)] ??= $canonicalTitle;
			}
		}
		return $map;
	}

	/**
	 * The canonical title of a card on a board, or the trimmed title itself when it
	 * is not a default card.
	 *
	 * @param IL10N[] $translators
	 */
	public static function canonicalTitle(string $title, array $translators): string
	{
		$title = trim($title);
		return self::canonicalTitleMap($translators)[mb_strtolower($title)] ?? $title;
	}

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

	/** @return array<string, string[]> */
	public static function getDefaultDependencyKeys(int $projectType): array
	{
		if ($projectType !== self::TYPE_COMBI) {
			return [];
		}

		return [
			'combi.peak_power_form' => ['combi.intake_form'],
			'combi.quickscan' => ['combi.intake_form'],
			'combi.situation_drawing' => ['combi.quickscan'],
			'combi.avp' => ['combi.peak_power_form'],
			'combi.vo' => ['combi.situation_drawing', 'combi.avp'],
			'combi.schedule_intake' => ['combi.vo'],
			'combi.intake_report' => ['combi.schedule_intake'],
			'combi.do' => ['combi.vo', 'combi.intake_report'],
			'combi.vo_internal_drawings' => ['combi.schedule_high_rise_consultation'],
			'combi.internal_consultation_report' => ['combi.vo_internal_drawings'],
			'combi.do_internal_drawings' => ['combi.internal_consultation_report'],
			'combi.block_diagram' => ['combi.do_internal_drawings'],
			'combi.soil_report' => ['combi.private_land_application'],
			'combi.remediation_evaluation_report' => ['combi.soil_report'],
			'combi.property_right' => ['combi.remediation_evaluation_report'],
		];
	}

	/** @return string[] */
	public static function getDefaultCardKeysInTimelineOrder(int $projectType): array
	{
		if ($projectType !== self::TYPE_COMBI) {
			return [];
		}

		return [
			'combi.intake_form',
			'combi.peak_power_form',
			'combi.quickscan',
			'combi.situation_drawing',
			'combi.avp',
			'combi.vo',
			'combi.schedule_intake',
			'combi.intake_report',
			'combi.do',
			'combi.schedule_high_rise_consultation',
			'combi.vo_internal_drawings',
			'combi.internal_consultation_report',
			'combi.do_internal_drawings',
			'combi.block_diagram',
			'combi.private_land_application',
			'combi.soil_report',
			'combi.remediation_evaluation_report',
			'combi.property_right',
			'combi.house_number_decision',
			'combi.guarantee_agreement',
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
