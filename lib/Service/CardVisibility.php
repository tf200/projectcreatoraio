<?php

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCP\AppFramework\OCS\OCSException;
use OCP\IL10N;

/**
 * Shared logic for the Combi intake form (card visibility questionnaire).
 *
 * Values stored on Project are the selected option values. Each option maps to a
 * "show" group:
 * - 0: always-visible (no conditional set)
 * - 1: enables conditional set 1
 * - 2: enables conditional set 2
 */
class CardVisibility
{
	public const FIELD_OBJECT_OWNERSHIP = 'cv_object_ownership';
	public const FIELD_TRACE_OWNERSHIP = 'cv_trace_ownership';
	public const FIELD_BUILDING_TYPE = 'cv_building_type';
	public const FIELD_AVP_LOCATION = 'cv_avp_location';

	/** @var array<string, array<int, int>>|null */
	private static ?array $showMap = null;

	/**
	 * The questions in the language of $l (the user's language when omitted).
	 * Logic uses the field keys and option values only, never the texts.
	 *
	 * @return array<int, array{field: string, category: string, question: string, options: array<int, array{label: string, value: int, show: int}>>>
	 */
	public static function getQuestions(?IL10N $l = null): array
	{
		$l ??= DefaultLanguageL10n::userL10n();
		return [
			[
				'field' => self::FIELD_OBJECT_OWNERSHIP,
				'category' => $l->t('Eigendoms situatie te realiseren object'),
				'question' => $l->t('Eigendoms situatie te realiseren object (antwoord met ja op de situatie die van toepassing is).'),
				'options' => [
					[
						'label' => $l->t('Het object(en) komt op eigen grond te staan en de gevel grenst direct aan gemeentegrond.'),
						'value' => 201,
						'show' => 0,
					],
					[
						'label' => $l->t('Het object komt op eigen grond te staan maar de gevel grenst niet direct aan gemeentegrond.'),
						'value' => 202,
						'show' => 2,
					],
					[
						'label' => $l->t('Het object komt op eigen grond te staan en de grond wordt overgedragen aan de gemeente.'),
						'value' => 203,
						'show' => 2,
					],
					[
						'label' => $l->t('Het object komt op openbare grond te staan.'),
						'value' => 204,
						'show' => 0,
					],
					[
						'label' => $l->t('Ik weet het nog niet.'),
						'value' => 205,
						'show' => 2,
					],
				],
			],
			[
				'field' => self::FIELD_TRACE_OWNERSHIP,
				'category' => $l->t('Eigendoms situatie kabel en leidingen tracé'),
				'question' => $l->t('Eigendoms situatie kabel en leidingen tracé (antwoord met ja op de situatie die van toepassing is).'),
				'options' => [
					[
						'label' => $l->t('Het vrije tracé komt in eigen grond te liggen.'),
						'value' => 211,
						'show' => 2,
					],
					[
						'label' => $l->t('Het vrije tracé komt in openbare grond te liggen.'),
						'value' => 212,
						'show' => 0,
					],
					[
						'label' => $l->t('Het vrije tracé komt zowel in eigen grond als in openbare grond te liggen.'),
						'value' => 213,
						'show' => 2,
					],
					[
						'label' => $l->t('Ik weet het nog niet.'),
						'value' => 214,
						'show' => 2,
					],
				],
			],
			[
				'field' => self::FIELD_BUILDING_TYPE,
				'category' => $l->t('Grondgebonden woningen/ hoogbouw/ bedrijfsunits'),
				'question' => $l->t('Grondgebonden woningen/ hoogbouw/ bedrijfsunits (antwoord met ja op de situatie die van toepassing is).'),
				'options' => [
					[
						'label' => $l->t('U realiseert grondgebonden woningen.'),
						'value' => 121,
						'show' => 0,
					],
					[
						'label' => $l->t('U realiseert appartementen.'),
						'value' => 122,
						'show' => 1,
					],
					[
						'label' => $l->t('U realiseert zowel grondgebonden woningen als appartementen.'),
						'value' => 123,
						'show' => 1,
					],
					[
						'label' => $l->t('U realiseert bedrijfsunits.'),
						'value' => 124,
						'show' => 0,
					],
				],
			],
			[
				'field' => self::FIELD_AVP_LOCATION,
				'category' => $l->t('AVP Locatie'),
				'question' => $l->t('AVP locatie (antwoord met ja op de situatie die van toepassing is).'),
				'options' => [
					[
						'label' => $l->t('Ik heb nog niet nagedacht over een mogelijke AVP.'),
						'value' => 221,
						'show' => 2,
					],
					[
						'label' => $l->t('Ik realiseer grondgebonden woningen en/of hoogbouw en/of bedrijfsunits. Er is rekening gehouden met een AVP op eigen grond.'),
						'value' => 222,
						'show' => 0,
					],
					[
						'label' => $l->t('Ik realiseer grondgebonden woningen en/of hoogbouw en/of bedrijfsunits. Er is geen rekening gehouden met een AVP op eigen grond.'),
						'value' => 223,
						'show' => 2,
					],
					[
						'label' => $l->t('Bij hoogbouw is de eis dat het AVP inpandig wordt opgenomen en daar is geen rekening mee gehouden.'),
						'value' => 224,
						'show' => 2,
					],
				],
			],
		];
	}

	/**
	 * @return array<string, array<int, int>>
	 */
	public static function getShowMap(): array
	{
		if (self::$showMap !== null) {
			return self::$showMap;
		}

		$map = [];
		foreach (self::getQuestions() as $question) {
			$field = (string)($question['field'] ?? '');
			if ($field === '') {
				continue;
			}

			$options = $question['options'] ?? [];
			if (!is_array($options)) {
				continue;
			}

			foreach ($options as $option) {
				if (!is_array($option)) {
					continue;
				}
				$value = (int)($option['value'] ?? 0);
				$show = (int)($option['show'] ?? 0);
				$map[$field][$value] = $show;
			}
		}

		self::$showMap = $map;
		return $map;
	}

	public static function resolveShow(string $field, ?int $value): ?int
	{
		if ($value === null) {
			return null;
		}

		if (in_array($value, [0, 1, 2], true)) {
			return $value;
		}

		$map = self::getShowMap();
		return $map[$field][$value] ?? 0;
	}

	/**
	 * @param mixed $value
	 */
	public static function normalizeAnswer(mixed $value, string $field, bool $allowNull = false, ?IL10N $l = null): ?int
	{
		if ($value === null) {
			return $allowNull ? null : 0;
		}

		if (is_string($value)) {
			$value = trim($value);
			if ($value === '') {
				return $allowNull ? null : 0;
			}
			if (is_numeric($value)) {
				$value = (int)$value;
			}
		}

		$showMap = self::getShowMap();
		$allowed = array_keys($showMap[$field] ?? []);
		$allowed[] = 0;
		$allowed[] = 1;
		$allowed[] = 2;
		$allowed = array_values(array_unique($allowed));

		if (!is_int($value) || !in_array($value, $allowed, true)) {
			$l ??= DefaultLanguageL10n::userL10n();
			throw new OCSException($l->t('Invalid value for %s. Allowed values: null, 0, 1, 2 or one of the configured option values.', [$field]), 400);
		}

		return $value;
	}

	/**
	 * @return array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int}
	 */
	public static function extractAnswers(Project $project): array
	{
		return [
			self::FIELD_OBJECT_OWNERSHIP => self::normalizeAnswer($project->getCvObjectOwnership(), self::FIELD_OBJECT_OWNERSHIP, true),
			self::FIELD_TRACE_OWNERSHIP => self::normalizeAnswer($project->getCvTraceOwnership(), self::FIELD_TRACE_OWNERSHIP, true),
			self::FIELD_BUILDING_TYPE => self::normalizeAnswer($project->getCvBuildingType(), self::FIELD_BUILDING_TYPE, true),
			self::FIELD_AVP_LOCATION => self::normalizeAnswer($project->getCvAvpLocation(), self::FIELD_AVP_LOCATION, true),
		];
	}

	/**
	 * @param array{cv_object_ownership: ?int, cv_trace_ownership: ?int, cv_building_type: ?int, cv_avp_location: ?int} $answers
	 * @return int[]
	 */
	public static function getEnabledSets(array $answers): array
	{
		$enabled = [];
		foreach ($answers as $field => $answer) {
			$show = self::resolveShow((string)$field, $answer);
			if ($show === 1) {
				$enabled[1] = true;
			} elseif ($show === 2) {
				$enabled[2] = true;
			}
		}

		$sets = array_keys($enabled);
		sort($sets);
		return array_values($sets);
	}

	/**
	 * @return int[]
	 */
	public static function getEnabledSetsForProject(Project $project): array
	{
		try {
			return self::getEnabledSets(self::extractAnswers($project));
		} catch (\Throwable $e) {
			// Be defensive: corrupted/legacy values should not break timeline/done-sync.
			return [];
		}
	}
}
