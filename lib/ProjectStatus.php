<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO;

use OCP\IL10N;

final class ProjectStatus
{
	public const ARCHIVED = 0;
	public const ACTIVE = 1;
	public const WAITING_ON_CUSTOMER = 2;
	public const ON_HOLD = 3;
	public const DONE = 4;

	/**
	 * @return int[]
	 */
	public static function all(): array
	{
		return [
			self::ARCHIVED,
			self::ACTIVE,
			self::WAITING_ON_CUSTOMER,
			self::ON_HOLD,
			self::DONE,
		];
	}

	/**
	 * English labels, kept for logs and callers without a translator.
	 * Logic and the API use the numeric codes above; labels are only for display.
	 */
	public const STATUS_LABELS = [
		self::ARCHIVED => 'Archived',
		self::ACTIVE => 'Active',
		self::WAITING_ON_CUSTOMER => 'Waiting on Customer',
		self::ON_HOLD => 'On Hold',
		self::DONE => 'Done',
	];

	public static function isValid(int $status): bool
	{
		return in_array($status, self::all(), true);
	}

	/**
	 * Display label for a status code. Pass a translator to get it in that language.
	 */
	public static function getLabel(int $status, ?IL10N $l = null): string
	{
		if ($l === null) {
			return self::STATUS_LABELS[$status] ?? 'Unknown';
		}

		return match ($status) {
			self::ARCHIVED => $l->t('Archived'),
			self::ACTIVE => $l->t('Active'),
			self::WAITING_ON_CUSTOMER => $l->t('Waiting on Customer'),
			self::ON_HOLD => $l->t('On Hold'),
			self::DONE => $l->t('Done'),
			default => $l->t('Unknown'),
		};
	}
}
