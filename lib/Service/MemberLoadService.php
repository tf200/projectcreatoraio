<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;
use OCP\IUserManager;

/**
 * Workload per person. Everyone in the team assigned to a project works on
 * it from its actual start until handover, and nobody should carry more
 * than MAX_CONCURRENT_PROJECTS projects in the same week. A person in
 * several teams carries the projects of all of them.
 */
class MemberLoadService {
	public const MAX_CONCURRENT_PROJECTS = 2;

	public function __construct(
		private IDBConnection $db,
		private ?IUserManager $userManager = null,
	) {
	}

	/**
	 * Days the team works on a project: from the actual start until the
	 * recorded handover, or until the last day of the planned execution
	 * weeks. Without either it stays open. Projects that have not started,
	 * that hand over before they start, or that were closed (done or
	 * archived) without a recorded handover are not counted.
	 *
	 * @param array<string,mixed> $project Keys status, actualStartDate, actualHandoverDate, executionWeeks
	 * @return array{start:string,end:?string}|null
	 */
	public static function busyWindow(array $project): ?array {
		$start = self::calendarDay($project['actualStartDate'] ?? null);
		if ($start === null) {
			return null;
		}
		$handover = self::calendarDay($project['actualHandoverDate'] ?? null);
		if ($handover === null && in_array((int)($project['status'] ?? ProjectStatus::ACTIVE), [ProjectStatus::DONE, ProjectStatus::ARCHIVED], true)) {
			return null;
		}
		$weeks = $project['executionWeeks'] ?? null;
		$end = $handover;
		if ($end === null && $weeks !== null) {
			// N weeks on site end the day before the N-th weekly anniversary.
			$end = (new DateTimeImmutable($start))->modify('+' . max(0, 7 * (int)$weeks - 1) . ' days')->format('Y-m-d');
		}
		if ($end !== null && $end < $start) {
			return null;
		}
		return ['start' => $start, 'end' => $end];
	}

	/**
	 * Load of every team member of the organization in the six weeks from
	 * the given Monday. Members without running projects have a load of 0.
	 * A week is free below the maximum, full at it and overloaded above it.
	 *
	 * @return array{
	 *     weeks: array<int,array{label:string,start:string,end:string}>,
	 *     members: array<string,array{uid:string,displayName:string,teamIds:int[],weeks:array<int,array{load:int,projectIds:int[],state:string}>}>,
	 *     projects: array<int,array{id:int,name:string,teamId:int,start:string,end:?string}>
	 * }
	 */
	public function getLoads(int $organizationId, DateTimeImmutable $monday, int $weekCount = 6): array {
		$memberships = $this->loadMemberships($organizationId);
		$projects = $this->loadTeamProjects($organizationId);
		$weeks = self::weeks($monday, $weekCount);
		$perWeek = self::projectsPerWeek($memberships, $projects, $weeks);

		$members = [];
		foreach ($memberships as $uid => $teamIds) {
			$memberWeeks = [];
			foreach ($perWeek[$uid] as $projectIds) {
				$memberWeeks[] = [
					'load' => count($projectIds),
					'projectIds' => $projectIds,
					'state' => self::state(count($projectIds)),
				];
			}
			$members[$uid] = [
				'uid' => $uid,
				'displayName' => $this->displayName($uid),
				'teamIds' => $teamIds,
				'weeks' => $memberWeeks,
			];
		}
		return ['weeks' => $weeks, 'members' => $members, 'projects' => $projects];
	}

	/** @return 'free'|'full'|'overloaded' */
	public static function state(int $load): string {
		if ($load > self::MAX_CONCURRENT_PROJECTS) {
			return 'overloaded';
		}
		return $load === self::MAX_CONCURRENT_PROJECTS ? 'full' : 'free';
	}

	/**
	 * Running project ids per member and week.
	 *
	 * @param array<string,int[]> $memberships uid => team ids
	 * @param array<int,array{teamId:int,start:string,end:?string}> $projects
	 * @param array<int,array{start:string,end:string}> $weeks
	 * @return array<string,array<int,int[]>> uid => week index => project ids
	 */
	public static function projectsPerWeek(array $memberships, array $projects, array $weeks): array {
		$byTeam = [];
		foreach ($projects as $id => $project) {
			$byTeam[(int)$project['teamId']][(int)$id] = $project;
		}

		$out = [];
		foreach ($memberships as $uid => $teamIds) {
			foreach ($weeks as $index => $week) {
				$running = [];
				foreach ($teamIds as $teamId) {
					foreach ($byTeam[$teamId] ?? [] as $id => $project) {
						if ($project['start'] <= $week['end'] && ($project['end'] === null || $project['end'] >= $week['start'])) {
							$running[$id] = $id;
						}
					}
				}
				sort($running);
				$out[(string)$uid][$index] = array_values($running);
			}
		}
		return $out;
	}

	/** @return array<int,array{label:string,start:string,end:string}> */
	public static function weeks(DateTimeImmutable $monday, int $count): array {
		$weeks = [];
		for ($i = 0; $i < $count; $i++) {
			$start = $monday->modify('+' . ($i * 7) . ' days');
			$weeks[] = [
				'label' => $start->format('o-\WW'),
				'start' => $start->format('Y-m-d'),
				'end' => $start->modify('+6 days')->format('Y-m-d'),
			];
		}
		return $weeks;
	}

	/** @return array<string,int[]> uid => team ids */
	private function loadMemberships(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('team_id', 'user_uid')
			->from('organization_team_members')
			->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		$memberships = [];
		foreach ($rows as $row) {
			$teamId = (int)$row['team_id'];
			$uid = (string)$row['user_uid'];
			if (!in_array($teamId, $memberships[$uid] ?? [], true)) {
				$memberships[$uid][] = $teamId;
			}
		}
		ksort($memberships);
		return $memberships;
	}

	/**
	 * Team-assigned projects that keep their team busy at some point.
	 *
	 * @return array<int,array{id:int,name:string,teamId:int,start:string,end:?string}>
	 */
	private function loadTeamProjects(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.actual_start_date', 'p.actual_handover_date', 'p.execution_weeks', 'pt.team_id')
			->from('custom_projects', 'p')
			->innerJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id AND pt.organization_id = p.organization_id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		$projects = [];
		foreach ($rows as $row) {
			$project = $this->mapProjectRow($row);
			if ($project !== null) {
				$project['teamId'] = (int)$row['team_id'];
				$projects[$project['id']] = $project;
			}
		}
		return $projects;
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array{id:int,name:string,start:string,end:?string}|null
	 */
	private function mapProjectRow(array $row): ?array {
		$window = self::busyWindow([
			'status' => (int)$row['status'],
			'actualStartDate' => $row['actual_start_date'] ?? null,
			'actualHandoverDate' => $row['actual_handover_date'] ?? null,
			'executionWeeks' => ($row['execution_weeks'] ?? null) === null ? null : (int)$row['execution_weeks'],
		]);
		if ($window === null) {
			return null;
		}
		return ['id' => (int)$row['id'], 'name' => (string)$row['name']] + $window;
	}

	private function displayName(string $uid): string {
		return $this->userManager?->getDisplayName($uid) ?? $uid;
	}

	/**
	 * Planning dates are stored as plain days; some databases append a
	 * midnight time, which must not be shifted into another time zone.
	 */
	private static function calendarDay(mixed $value): ?string {
		if (!is_string($value)) {
			return null;
		}
		$day = substr(trim($value), 0, 10);
		return ProjectPortfolioService::isIsoDate($day) ? $day : null;
	}
}
