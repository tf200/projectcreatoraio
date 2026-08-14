<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO;

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

	public static function getLabel(int $status): string
	{
		return self::STATUS_LABELS[$status] ?? 'Unknown';
	}
}
