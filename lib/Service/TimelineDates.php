<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;
use InvalidArgumentException;
use OCA\ProjectCreatorAIO\Db\Project;

/**
 * The timeline dates people type in. Each must be a real day, and the
 * handover, actual start and end must follow each other in that order.
 * Only rules that touch a changed date are checked, so a project with
 * older out-of-order dates can still be edited elsewhere.
 */
final class TimelineDates
{
	public const HANDOVER = 'handover';
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
	public static function parse(mixed $value, string $label): ?DateTime
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
			throw new InvalidArgumentException(sprintf('The %s must be a date like 2026-10-28.', $label));
		}
		$year = (int)$date->format('Y');
		if ($year < self::FIRST_YEAR || $year > self::LAST_YEAR) {
			throw new InvalidArgumentException(sprintf('The %s must fall between %d and %d.', $label, self::FIRST_YEAR, self::LAST_YEAR));
		}
		return $date;
	}

	/**
	 * @param list<string> $changed The dates just set, as the constants above
	 * @throws InvalidArgumentException
	 */
	public static function assertInOrder(Project $project, array $changed): void
	{
		$dates = [
			self::HANDOVER => self::day($project->getActualHandoverDate()),
			self::ACTUAL_START => self::day($project->getActualStartDate()),
			self::ACTUAL_END => self::day($project->getActualEndDate()),
		];

		// The end is the last day on site, so the work has to have started.
		if ($dates[self::ACTUAL_END] !== null && $dates[self::ACTUAL_START] === null) {
			if (in_array(self::ACTUAL_END, $changed, true)) {
				throw new InvalidArgumentException('Set the actual start before the end date.');
			}
			if (in_array(self::ACTUAL_START, $changed, true)) {
				throw new InvalidArgumentException('Clear the end date before the actual start.');
			}
		}

		foreach (self::ORDER as [$earlier, $later]) {
			$first = $dates[$earlier];
			$second = $dates[$later];
			if ($first === null || $second === null || $second >= $first) {
				continue;
			}
			if (in_array($later, $changed, true)) {
				throw new InvalidArgumentException(sprintf('The %s (%s) can\'t be before the %s (%s).', $later, self::label($second), $earlier, self::label($first)));
			}
			if (in_array($earlier, $changed, true)) {
				throw new InvalidArgumentException(sprintf('The %s (%s) can\'t be after the %s (%s).', $earlier, self::label($first), $later, self::label($second)));
			}
		}
	}

	private static function day(mixed $date): ?string
	{
		return $date instanceof \DateTimeInterface ? $date->format('Y-m-d') : null;
	}

	// "28 Oct 2026"
	private static function label(string $day): string
	{
		return (DateTime::createFromFormat('!Y-m-d', $day) ?: new DateTime($day))->format('j M Y');
	}
}
