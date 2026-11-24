<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use InvalidArgumentException;
use OCA\ProjectCreatorAIO\Db\Project;
use OCP\IL10N;

/**
 * The timeline dates people type in. Saves are refused only when the order
 * is impossible: every date is a real day on or after the request date, and
 * the handover, actual start and end follow each other, and a desired start
 * isn't planned before the minimum start. How the dates compare with the
 * plan otherwise is a warning, see
 * TimelinePlanningService::dateWarnings(). Only rules that touch a changed
 * date are checked, so a project with older out-of-order dates can still be
 * edited elsewhere.
 */
final class TimelineDates
{
	// Keys (also used as array keys); translated only when a message is built, see name().
	public const HANDOVER = 'handover';
	public const DESIRED_START = 'desired start';
	public const ACTUAL_START = 'actual start';
	public const ACTUAL_END = 'end date';

	// Catches a mistyped year such as 0226 or 20266.
	private const FIRST_YEAR = 2000;
	private const LAST_YEAR = 2100;

	/** Pairs that must not be reversed: [earlier, later] */
	private const ORDER = [
		[self::HANDOVER, self::ACTUAL_START],
		[self::ACTUAL_START, self::ACTUAL_END],
		[self::HANDOVER, self::ACTUAL_END],
	];

	/**
	 * A day as YYYY-MM-DD (a time after it is ignored); null, '' and 'null' clear it.
	 *
	 * @throws InvalidArgumentException
	 */
	public static function parse(mixed $value, string $label, ?IL10N $l = null): ?DateTime
	{
		if ($value === null) {
			return null;
		}
		$value = is_string($value) ? trim($value) : $value;
		if ($value === '' || $value === 'null') {
			return null;
		}
		$day = is_string($value) ? substr($value, 0, 10) : '';
		$date = DateTime::createFromFormat('!Y-m-d', $day);
		if (!is_string($value) || $date === false || $date->format('Y-m-d') !== $day || (strlen($value) > 10 && $value[10] !== 'T')) {
			$l ??= DefaultLanguageL10n::userL10n();
			throw new InvalidArgumentException($l->t('The %s must be a date like 2026-10-28.', [self::name($label, $l)]));
		}
		$year = (int)$date->format('Y');
		if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
			$l ??= DefaultLanguageL10n::userL10n();
			throw new InvalidArgumentException($l->t('The %s must fall between %d and %d.', [self::name($label, $l), self::FIRST_YEAR, self::LAST_YEAR]));
		}
		return $date;
	}

	/**
	 * @param list<string> $changed The dates just set, as the constants above
	 * @param ?string $minimumStart The computed minimum start (Y-m-d); a desired start just set can't be before it
	 * @throws InvalidArgumentException
	 */
	public static function assertInOrder(Project $project, array $changed, ?IL10N $l = null, ?string $minimumStart = null): void
	{
		$l ??= DefaultLanguageL10n::userL10n();
		$dates = [
			self::HANDOVER => self::day($project->getActualHandoverDate()),
			self::DESIRED_START => self::day($project->getDesiredStartDate()),
			self::ACTUAL_START => self::day($project->getActualStartDate()),
			self::ACTUAL_END => self::day($project->getActualEndDate()),
		];

		// Nothing on the timeline happens before the project was requested.
		$request = self::requestDate($project);
		if ($request !== null) {
			foreach ($changed as $name) {
				if (($dates[$name] ?? null) !== null && $dates[$name] < $request) {
					throw new InvalidArgumentException($l->t('The %s (%s) can\'t be before the request date (%s).', [self::name($name, $l), self::label($dates[$name], $l), self::label($request, $l)]));
				}
			}
		}

		// Planning to start before initiation and preparation can be done is
		// impossible. The minimum start moves with the Deck cards, so a desired
		// start it later overtakes is only warned about.
		$minimum = $minimumStart === null || $minimumStart === '' ? null : substr($minimumStart, 0, 10);
		if ($minimum !== null && $dates[self::DESIRED_START] !== null && $dates[self::DESIRED_START] < $minimum && in_array(self::DESIRED_START, $changed, true)) {
			throw new InvalidArgumentException($l->t('The desired start (%s) can\'t be before the minimum start (%s).', [self::label($dates[self::DESIRED_START], $l), self::label($minimum, $l)]));
		}

		// The end is the last day on site, so the work has to have started.
		if ($dates[self::ACTUAL_END] !== null && $dates[self::ACTUAL_START] === null) {
			if (in_array(self::ACTUAL_END, $changed, true)) {
				throw new InvalidArgumentException($l->t('Set the actual start before the end date.'));
			}
			if (in_array(self::ACTUAL_START, $changed, true)) {
				throw new InvalidArgumentException($l->t('Clear the end date before the actual start.'));
			}
		}

		foreach (self::ORDER as [$earlier, $later]) {
			$first = $dates[$earlier];
			$second = $dates[$later];
			if ($first === null || $second === null || $second >= $first) {
				continue;
			}
			if (in_array($later, $changed, true)) {
				throw new InvalidArgumentException($l->t('The %s (%s) can\'t be before the %s (%s).', [self::name($later, $l), self::label($second, $l), self::name($earlier, $l), self::label($first, $l)]));
			}
			if (in_array($earlier, $changed, true)) {
				throw new InvalidArgumentException($l->t('The %s (%s) can\'t be after the %s (%s).', [self::name($earlier, $l), self::label($first, $l), self::name($later, $l), self::label($second, $l)]));
			}
		}
	}

	/**
	 * The timeline's first day: the first Monday on or after the project was created.
	 */
	public static function requestDate(Project $project): ?string
	{
		$created = $project->getCreatedAt();
		if (!$created instanceof \DateTimeInterface) {
			return null;
		}
		$day = DateTime::createFromFormat('!Y-m-d', $created->format('Y-m-d'));
		$daysUntilMonday = (8 - (int)$day->format('N')) % 7;
		if ($daysUntilMonday > 0) {
			$day->modify('+' . $daysUntilMonday . ' days');
		}
		return $day->format('Y-m-d');
	}

	private static function day(mixed $date): ?string
	{
		return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null;
	}

	// "28 Oct 2026" / "28 okt. 2026"
	private static function label(string $day, IL10N $l): string
	{
		$date = DateTime::createFromFormat('!Y-m-d', $day) ?: new DateTime($day);
		$formatted = $l->l('date', $date, ['width' => 'medium']);
		return is_string($formatted) ? $formatted : $date->format('j M Y');
	}

	/**
	 * The name of a date for messages. The constants above are keys; a caller may
	 * also pass a name it already translated.
	 */
	private static function name(string $label, IL10N $l): string
	{
		return match ($label) {
			self::HANDOVER => $l->t('handover'),
			self::DESIRED_START => $l->t('desired start'),
			self::ACTUAL_START => $l->t('actual start'),
			self::ACTUAL_END => $l->t('end date'),
			default => $label,
		};
	}
}
