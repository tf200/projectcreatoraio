<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\ProjectStatus;
use OCP\DB\QueryBuilder\IQueryBuilder;
use OCP\IDBConnection;

class ProjectPortfolioService {
	/**
	 * Project statuses that are still in the initiation phase. ProjectStatus
	 * is the authoritative project-level phase representation: there is no
	 * separate "initiation" status, so initiation means any live,
	 * non-archived project that has not reached done. Timeline phases
	 * (category 'initiation') only exist for Combi-type projects and
	 * describe task breakdowns, not project membership.
	 */
	public const INITIATION_STATUSES = [ProjectStatus::ACTIVE, ProjectStatus::WAITING_ON_CUSTOMER, ProjectStatus::ON_HOLD];
	private const CAPACITY_STATUSES = self::INITIATION_STATUSES;
	private const HANDOVER_TITLE = 'handover 1';
	private const BUCKETS = [
		['key' => '0-24', 'label' => '0 - 24%', 'min' => 0, 'max' => 24],
		['key' => '25-49', 'label' => '25 - 49%', 'min' => 25, 'max' => 49],
		['key' => '50-74', 'label' => '50 - 74%', 'min' => 50, 'max' => 74],
		['key' => '75-99', 'label' => '75 - 99%', 'min' => 75, 'max' => 99],
		['key' => '100', 'label' => '100%', 'min' => 100, 'max' => 100],
	];

	public function __construct(
		private ProjectMapper $projectMapper,
		private IDBConnection $db,
	) {
	}

	public function getCompletion(int $organizationId, ?string $memberUid = null, ?int $teamId = null): array {
		$allProjects = $this->projectMapper->findByOrganizationId($organizationId);
		if ($teamId !== null) {
			$team = $this->loadTeam($organizationId, $teamId);
			if ($team === null) {
				throw new \InvalidArgumentException('Team does not belong to organization');
			}
			$teamProjectIds = $this->loadTeamProjectIds($organizationId, $teamId);
			$allProjects = array_values(array_filter(
				$allProjects,
				static fn (Project $project): bool => isset($teamProjectIds[(int)$project->getId()]),
			));
		}
		if ($memberUid !== null) {
			$gids = [];
			foreach ($allProjects as $project) {
				$gids[] = $project->getProjectGroupGid();
			}
			$memberGids = $this->loadMemberProjectGids($memberUid, $gids);
			$allProjects = array_values(array_filter(
				$allProjects,
				fn (Project $project): bool => $this->isMemberProject(
					['ownerId' => $project->getOwnerId(), 'projectGroupGid' => $project->getProjectGroupGid()],
					$memberUid,
					$memberGids,
				),
			));
		}

		$statusCounts = [
			'active' => 0,
			'waiting' => 0,
			'on_hold' => 0,
			'done' => 0,
			'archived' => 0,
		];
		foreach ($allProjects as $p) {
			switch ((int)$p->getStatus()) {
				case ProjectStatus::ACTIVE:
					$statusCounts['active']++;
					break;
				case ProjectStatus::WAITING_ON_CUSTOMER:
					$statusCounts['waiting']++;
					break;
				case ProjectStatus::ON_HOLD:
					$statusCounts['on_hold']++;
					break;
				case ProjectStatus::DONE:
					$statusCounts['done']++;
					break;
				case ProjectStatus::ARCHIVED:
					$statusCounts['archived']++;
					break;
			}
		}

		$projects = array_values(array_filter(
			$allProjects,
			static fn (Project $project): bool => in_array($project->getStatus(), self::INITIATION_STATUSES, true),
		));

		$boardIds = [];
		foreach ($projects as $project) {
			$boardId = $this->normalizeBoardId($project->getBoardId());
			if ($boardId !== null) {
				$boardIds[$boardId] = $boardId;
			}
		}

		$liveBoardIds = $this->getLiveBoardIds(array_values($boardIds));
		$cardCounts = $this->getCardCounts($liveBoardIds);
		$projectRows = [];
		$untrackedProjects = [];

		foreach ($projects as $project) {
			$boardId = $this->normalizeBoardId($project->getBoardId());
			if ($boardId === null || !isset($liveBoardIds[$boardId])) {
				$untrackedProjects[] = [
					'id' => (int)$project->getId(),
					'name' => (string)$project->getName(),
					'status' => (int)$project->getStatus(),
				];
				continue;
			}

			$counts = $cardCounts[$boardId] ?? ['total' => 0, 'done' => 0];
			$projectRows[] = [
				'id' => (int)$project->getId(),
				'name' => (string)$project->getName(),
				'boardId' => $boardId,
				'totalCards' => $counts['total'],
				'doneCards' => $counts['done'],
				'status' => (int)$project->getStatus(),
			];
		}

		return $this->summarize($projectRows, $untrackedProjects, $statusCounts);
	}

	public static function isIsoDate(string $value): bool {
		$date = DateTimeImmutable::createFromFormat('!Y-m-d', $value);
		return $date !== false && $date->format('Y-m-d') === $value;
	}

	/**
	 * Fetches the portfolio in batches. The card query deliberately returns done as text:
	 * PostgreSQL Deck installations have used both datetime and date-like values there.
	 */
	public function getCapacity(int $organizationId, int $teamId, ?string $weekStart = null): array {
		$requestedMonday = $this->normalizeMonday($weekStart);
		$team = $this->loadTeam($organizationId, $teamId);
		if ($team === null) {
			throw new \InvalidArgumentException('Team does not belong to organization');
		}

		$projects = $this->loadCapacityProjects($organizationId, $teamId);
		[$eligible, $planningGaps] = $this->deriveEligibleCapacityProjects($projects, $requestedMonday);

		$unassigned = $this->loadUnassignedProjects($organizationId);
		return $this->summarizeCapacity($team, $requestedMonday->format('Y-m-d'), $eligible, $planningGaps, $unassigned);
	}

	/**
	 * Aggregate capacity across all teams of the organization. The combined
	 * strip reuses summarizeCapacity() with a synthetic team row whose
	 * capacity is the sum of the teams' capacities; per-team over-capacity
	 * weeks are reported separately so one team's overload cannot hide
	 * behind another team's slack.
	 */
	public function getCapacityForAll(int $organizationId, ?string $weekStart = null, ?string $memberUid = null): array {
		$requestedMonday = $this->normalizeMonday($weekStart);
		$weekStartDate = $requestedMonday->format('Y-m-d');
		$projects = $this->applyMemberFilter($this->loadAllCapacityProjects($organizationId), $memberUid);
		[$eligible, $planningGaps] = $this->deriveEligibleCapacityProjects($projects, $requestedMonday);
		$planningGaps = $this->dedupeCapacityGaps($planningGaps);
		if ($memberUid !== null) {
			$teamRows = $this->loadInvolvedTeams($organizationId, $projects);
			$allRow = $this->buildAllTeamsRow($teamRows, $organizationId, 'My teams');
		} else {
			$teamRows = $this->loadTeams($organizationId);
			$allRow = $this->buildAllTeamsRow($teamRows, $organizationId);
		}

		$unassigned = $this->applyMemberFilter($this->loadUnassignedProjects($organizationId), $memberUid);
		$result = $this->summarizeCapacity($allRow, $weekStartDate, $eligible, $planningGaps, $unassigned);
		$result['teams'] = $this->summarizeTeams($teamRows);
		$result['teamWarnings'] = $this->buildTeamWarnings($this->summarizeTeamsInPeriod($organizationId, $teamRows, $requestedMonday, $weekStartDate));
		return $result;
	}

	/**
	 * Table overview ("Planningsoverzicht – Initiatiefase") providing detailed
	 * metrics for all initiation-phase projects in the requested scope,
	 * along with the 6-week capacity load strip and status counts.
	 *
	 * @return array<string, mixed>
	 */
	public function getTableOverview(
		int $organizationId,
		?string $weekStart = null,
		?int $teamId = null,
		string $scope = 'all',
		?string $memberUid = null,
	): array {
		$requestedMonday = $this->normalizeMonday($weekStart);
		$currentMonday = $this->normalizeMonday(null);

		if ($scope === 'team' && $teamId !== null) {
			$team = $this->loadTeam($organizationId, $teamId);
			if ($team === null) {
				throw new \InvalidArgumentException('Team does not belong to organization');
			}
			$capacitySummary = $this->getCapacity($organizationId, $teamId, $requestedMonday->format('Y-m-d'));
			$rawProjects = $this->loadTableProjects($organizationId, $teamId);
		} elseif ($scope === 'mine') {
			$capacitySummary = $this->getCapacityForAll($organizationId, $requestedMonday->format('Y-m-d'), $memberUid);
			$rawProjects = $this->applyMemberFilter($this->loadAllTableProjects($organizationId), $memberUid);
		} else {
			$capacitySummary = $this->getCapacityForAll($organizationId, $requestedMonday->format('Y-m-d'));
			$rawProjects = $this->loadAllTableProjects($organizationId);
		}

		$boardIds = [];
		foreach ($rawProjects as $project) {
			$boardId = $this->normalizeBoardId($project['boardId']);
			if ($boardId !== null) {
				$boardIds[$boardId] = $boardId;
			}
		}
		$cardsByBoard = $this->loadCapacityCards(array_values($boardIds));

		return $this->buildTableOverview($rawProjects, $cardsByBoard, $capacitySummary, $currentMonday, $requestedMonday);
	}

	/**
	 * Pure presentation mapping for the Planningsoverzicht table.
	 *
	 * @param array<int, array<string, mixed>> $rawProjects
	 * @param array<int, array<int, array<string, mixed>>> $cardsByBoard
	 * @param array<string, mixed> $capacitySummary
	 * @return array<string, mixed>
	 */
	public function buildTableOverview(
		array $rawProjects,
		array $cardsByBoard,
		array $capacitySummary,
		?DateTimeImmutable $currentMonday = null,
		?DateTimeImmutable $requestedMonday = null,
	): array {
		$currentMonday ??= $this->normalizeMonday(null);
		$requestedMonday ??= $this->normalizeMonday(null);

		$bucketCounters = [
			'0-24' => 0,
			'25-49' => 0,
			'50-74' => 0,
			'75-99' => 0,
			'100' => 0,
		];
		$planningGapCount = 0;
		$projectRows = [];

		foreach ($rawProjects as $project) {
			$boardId = $this->normalizeBoardId($project['boardId'] ?? null);
			$cards = $boardId !== null ? ($cardsByBoard[$boardId] ?? []) : [];
			$totalCards = count($cards);

			$doneStackId = $this->resolveCapacityDoneStackId($cards);
			$doneCards = 0;
			foreach ($cards as $card) {
				$hasDoneFlag = isset($card['done']) && trim((string)$card['done']) !== '';
				$inDoneStack = $doneStackId !== null && (int)($card['stack_id'] ?? -1) === $doneStackId;
				if ($hasDoneFlag || $inDoneStack) {
					$doneCards++;
				}
			}
			$openCards = max(0, $totalCards - $doneCards);
			// A project is 100% only when every card is done (and at least
			// one card exists). Rounding alone must never promote 199/200
			// style rows into the 100% bucket.
			$isFullyDone = $totalCards > 0 && $doneCards === $totalCards;
			$completionPct = $isFullyDone
				? 100
				: ($totalCards === 0 ? 0 : min(99, (int)round(($doneCards / $totalCards) * 100)));
			$bucketIndex = min(4, intdiv($completionPct, 25));
			$bucketKey = self::BUCKETS[$bucketIndex]['key'];
			$bucketCounters[$bucketKey]++;

			$bucketLabels = [
				'0-24' => '0–24%',
				'25-49' => '25–49%',
				'50-74' => '50–74%',
				'75-99' => '75–99% / Upcoming',
				'100' => '100% ready for Handover 1',
			];
			$bucketLabel = $bucketLabels[$bucketKey] ?? self::BUCKETS[$bucketIndex]['label'];

			$dates = $this->deriveCapacityDates($project, $cards);
			$actualEnd = $dates['actualEnd'];
			$plannedEnd = $dates['end'];
			$isCompleted = $isFullyDone && $actualEnd !== null;
			$actualEndMonday = $isCompleted ? $this->normalizeMonday($actualEnd) : null;

			if ($isCompleted && $actualEndMonday !== null) {
				$expectedOrAchievedLabel = '100% reached ' . $this->isoWeekLabel($actualEndMonday);
			} elseif ($plannedEnd !== null) {
				$expectedOrAchievedLabel = $this->isoWeekLabel($this->normalizeMonday($plannedEnd));
			} else {
				$expectedOrAchievedLabel = '—';
			}

			$endForPrep = $actualEnd ?? $plannedEnd;
			if ($endForPrep !== null && !$dates['invalidEnd']) {
				$startPrepDate = $this->firstMondayOnOrAfter($endForPrep)->format('Y-m-d');
				$startPrepMonday = $this->normalizeMonday($startPrepDate);
				$startPrepWeek = $this->isoWeekLabel($startPrepMonday);

				if ($isCompleted) {
					$startPrepCountdown = '—';
					$startPrepCountdownWeeks = null;
				} else {
					$diffDays = (int)$currentMonday->diff($startPrepMonday)->format('%r%a');
					$diffWeeks = (int)round($diffDays / 7);
					$startPrepCountdownWeeks = $diffWeeks;
					if ($diffWeeks > 0) {
						$startPrepCountdown = $diffWeeks . ' ' . ($diffWeeks === 1 ? 'week' : 'weeks');
					} elseif ($diffWeeks === 0) {
						$startPrepCountdown = 'this week';
					} else {
						$startPrepCountdown = abs($diffWeeks) . ' ' . (abs($diffWeeks) === 1 ? 'week ago' : 'weeks ago');
					}
				}
			} else {
				$startPrepDate = null;
				$startPrepMonday = null;
				$startPrepWeek = '—';
				$startPrepCountdown = '—';
				$startPrepCountdownWeeks = null;
			}

			$prepWeeks = max(0, (int)($project['requiredPreparationWeeks'] ?? 0));
			if ($startPrepMonday !== null) {
				$minExecMonday = $startPrepMonday->modify('+' . ($prepWeeks * 7) . ' days');
				$minExecutionStartDate = $minExecMonday->format('Y-m-d');
				$minExecutionStartWeek = $this->isoWeekLabel($minExecMonday);
			} else {
				$minExecMonday = null;
				$minExecutionStartDate = null;
				$minExecutionStartWeek = '—';
			}

			$desiredStartDate = $project['desiredStartDate'] ?? null;
			// Persisted desired dates may be malformed; a single bad row
			// must degrade to "no desired date" instead of failing the
			// whole endpoint.
			$desiredMonday = $this->tryParseMonday(is_string($desiredStartDate) ? $desiredStartDate : null);
			if ($desiredMonday !== null) {
				$desiredStartWeek = $this->isoWeekLabel($desiredMonday);
				$diffDesiredDays = (int)$currentMonday->diff($desiredMonday)->format('%r%a');
				$diffDesiredWeeks = (int)round($diffDesiredDays / 7);
				$desiredCountdownWeeks = $diffDesiredWeeks;
				if ($diffDesiredWeeks > 0) {
					$desiredCountdown = $diffDesiredWeeks . ' ' . ($diffDesiredWeeks === 1 ? 'week' : 'weeks');
				} elseif ($diffDesiredWeeks === 0) {
					$desiredCountdown = 'this week';
				} else {
					$desiredCountdown = abs($diffDesiredWeeks) . ' ' . (abs($diffDesiredWeeks) === 1 ? 'week ago' : 'weeks ago');
				}
			} else {
				$desiredStartWeek = '—';
				$desiredCountdown = '—';
				$desiredCountdownWeeks = null;
			}
			$actualStartDate = $project['actualStartDate'] ?? null;
			$actualStartMonday = $this->tryParseMonday(is_string($actualStartDate) ? $actualStartDate : null);
			$actualStartWeek = $actualStartMonday !== null ? $this->isoWeekLabel($actualStartMonday) : '—';

			// Leading means the project actually completed ahead of its
			// desired week (strictly before that week's Monday), not merely
			// that a desired date exists.
			$isLeadingDesiredWeek = $isCompleted && $actualEndMonday !== null && $desiredMonday !== null && $actualEndMonday < $desiredMonday;

			if ($dates['invalidEnd'] || $endForPrep === null) {
				$hasGap = true;
				$planningGapDisplay = 'No end date';
				$gapWeeks = 0;
				$gapSpan = null;
			} elseif ($minExecMonday !== null && $desiredMonday !== null && $minExecMonday > $desiredMonday) {
				$hasGap = true;
				$diffGapDays = (int)$desiredMonday->diff($minExecMonday)->format('%r%a');
				$gapWeeks = max(1, (int)round($diffGapDays / 7) + 1);
				$gapSpan = $this->isoWeekLabel($desiredMonday) . '-' . $this->isoWeekLabel($minExecMonday);
				$planningGapDisplay = "{$gapWeeks} " . ($gapWeeks === 1 ? 'week' : 'weeks') . " · {$gapSpan}";
			} else {
				$hasGap = false;
				$planningGapDisplay = 'None';
				$gapWeeks = 0;
				$gapSpan = null;
			}

			if ($hasGap) {
				$planningGapCount++;
			}

			$projectRows[] = [
				'id' => (int)$project['id'],
				'name' => (string)$project['name'],
				'boardId' => $boardId,
				'status' => (int)$project['status'],
				'bucket' => $bucketKey,
				'bucketLabel' => $bucketLabel,
				'completionPct' => $completionPct,
				'totalCards' => $totalCards,
				'doneCards' => $doneCards,
				'openCards' => $openCards,
				'actualEnd' => $actualEnd,
				'plannedEnd' => $plannedEnd,
				'isCompleted' => $isCompleted,
				'expectedOrAchievedLabel' => $expectedOrAchievedLabel,
				'startPrepDate' => $startPrepDate,
				'startPrepWeek' => $startPrepWeek,
				'startPrepCountdown' => $startPrepCountdown,
				'startPrepCountdownWeeks' => $startPrepCountdownWeeks,
				'requiredPrepWeeks' => $prepWeeks,
				'minExecutionStartDate' => $minExecutionStartDate,
				'minExecutionStartWeek' => $minExecutionStartWeek,
				'desiredStartDate' => $desiredStartDate,
				'desiredStartWeek' => $desiredStartWeek,
				'desiredCountdown' => $desiredCountdown,
				'desiredCountdownWeeks' => $desiredCountdownWeeks,
				'actualStartDate' => $actualStartDate,
				'actualStartWeek' => $actualStartWeek,
				'isLeadingDesiredWeek' => $isLeadingDesiredWeek,
				'planningGap' => [
					'hasGap' => $hasGap,
					'weeks' => $gapWeeks,
					'spanLabel' => $gapSpan,
					'display' => $planningGapDisplay,
				],
				'teamId' => $project['teamId'] ?? null,
			];
		}

		$totalProjects = count($projectRows);
		$bucketSummaries = [
			['key' => 'all', 'label' => 'All statuses', 'count' => $totalProjects],
			['key' => '0-24', 'label' => '0–24%', 'count' => $bucketCounters['0-24']],
			['key' => '25-49', 'label' => '25–49%', 'count' => $bucketCounters['25-49']],
			['key' => '50-74', 'label' => '50–74%', 'count' => $bucketCounters['50-74']],
			['key' => '75-99', 'label' => '75–99% / Upcoming', 'count' => $bucketCounters['75-99']],
			['key' => '100', 'label' => '100% ready for Handover 1', 'count' => $bucketCounters['100']],
			['key' => 'gaps', 'label' => 'Open planning gaps', 'count' => $planningGapCount],
		];

		return [
			'period' => $capacitySummary['period'] ?? [
				'weekStart' => $requestedMonday->format('Y-m-d'),
				'weekEnd' => $requestedMonday->modify('+41 days')->format('Y-m-d'),
				'weeks' => 6,
			],
			'currentWeek' => [
				'isoYear' => (int)$currentMonday->format('o'),
				'week' => (int)$currentMonday->format('W'),
				'label' => $this->isoWeekLabel($currentMonday),
				'date' => $currentMonday->format('Y-m-d'),
			],
			'team' => $capacitySummary['team'] ?? null,
			'teams' => $capacitySummary['teams'] ?? [],
			'teamWarnings' => $capacitySummary['teamWarnings'] ?? [],
			'weeks' => $capacitySummary['weeks'] ?? [],
			'totalProjects' => $totalProjects,
			'planningGapCount' => $planningGapCount,
			'buckets' => $bucketSummaries,
			'projects' => $projectRows,
		];
	}

	/**
	 * @param array<int,array<string,mixed>> $teams Raw team rows with fte/projectsPerFte keys
	 * @return array<int,array{id:int,name:string,capacity:float}>
	 */
	public function summarizeTeams(array $teams): array {
		$out = [];
		foreach ($teams as $team) {
			$projectsPerFte = (float)($team['projectsPerFte'] ?? $team['projects_per_fte'] ?? 1.0);
			$out[] = [
				'id' => (int)$team['id'],
				'name' => (string)$team['name'],
				'capacity' => round((float)($team['fte'] ?? 0.0) * $projectsPerFte, 2),
			];
		}
		return $out;
	}

	/**
	 * @param array<int,array<string,mixed>> $teams Raw team rows with fte/projectsPerFte keys
	 * @return array<string,mixed> Synthetic team row carrying the summed capacity
	 */
	public function buildAllTeamsRow(array $teams, int $organizationId, string $name = 'All teams'): array {
		$total = 0.0;
		foreach ($this->summarizeTeams($teams) as $summary) {
			$total = round($total + $summary['capacity'], 2);
		}
		return ['id' => 0, 'organizationId' => $organizationId, 'name' => $name, 'fte' => $total, 'projectsPerFte' => 1.0];
	}

	/**
	 * @param array<int,array<string,mixed>> $gaps
	 * @return array<int,array<string,mixed>>
	 */
	public function dedupeCapacityGaps(array $gaps): array {
		$out = [];
		foreach ($gaps as $gap) {
			$id = (int)($gap['id'] ?? 0);
			if (!isset($out[$id])) {
				$out[$id] = $gap;
			}
		}
		return array_values($out);
	}

	/**
	 * @param array<int,array{id:int,name:string,weeks:array<int,array<string,mixed>>}> $summaries
	 * @return array<int,array{id:int,name:string,overWeeks:array<int,string>}>
	 */
	public function buildTeamWarnings(array $summaries): array {
		$warnings = [];
		foreach ($summaries as $summary) {
			$overWeeks = [];
			foreach ($summary['weeks'] as $week) {
				if (!empty($week['overCapacity'])) {
					$overWeeks[] = $week['label'];
				}
			}
			if ($overWeeks !== []) {
				$warnings[] = ['id' => (int)$summary['id'], 'name' => (string)$summary['name'], 'overWeeks' => $overWeeks];
			}
		}
		return $warnings;
	}

	/**
	 * "My projects" predicate: project owner or member of the project group.
	 * Mirrors the membership definition in ProjectMemberResolver.
	 *
	 * @param array{ownerId?:?string,projectGroupGid?:?string} $project
	 * @param array<string,true> $memberGids Project-group gids the user belongs to
	 */
	public function isMemberProject(array $project, string $uid, array $memberGids): bool {
		if (trim((string)($project['ownerId'] ?? '')) === trim($uid) && trim($uid) !== '') {
			return true;
		}
		$gid = trim((string)($project['projectGroupGid'] ?? ''));
		return $gid !== '' && isset($memberGids[$gid]);
	}

	/**
	 * Filters capacity project rows down to the user's own projects.
	 * Null uid disables the filter.
	 *
	 * @param array<int,array<string,mixed>> $projects Rows with ownerId/projectGroupGid keys
	 * @return array<int,array<string,mixed>>
	 */
	private function applyMemberFilter(array $projects, ?string $uid): array {
		if ($uid === null) {
			return $projects;
		}
		$gids = [];
		foreach ($projects as $project) {
			$gids[] = $project['projectGroupGid'] ?? null;
		}
		$memberGids = $this->loadMemberProjectGids($uid, $gids);
		return array_values(array_filter(
			$projects,
			fn (array $project): bool => $this->isMemberProject($project, $uid, $memberGids),
		));
	}

	/**
	 * Single query resolving which of the given project-group gids the user
	 * belongs to. Keeps the Mine filter free of per-project group lookups.
	 *
	 * @param array<int,?string> $groupGids
	 * @return array<string,true>
	 */
	private function loadMemberProjectGids(string $uid, array $groupGids): array {
		$gids = [];
		foreach ($groupGids as $gid) {
			$gid = trim((string)$gid);
			if ($gid !== '') {
				$gids[$gid] = true;
			}
		}
		if ($gids === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('gid')
			->from('group_user')
			->where($qb->expr()->eq('uid', $qb->createNamedParameter($uid)))
			->andWhere($qb->expr()->in('gid', $qb->createNamedParameter(array_keys($gids), IQueryBuilder::PARAM_STR_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$out = [];
		foreach ($rows as $row) {
			$out[(string)$row['gid']] = true;
		}
		return $out;
	}

	/**
	 * Teams behind the given capacity projects (assignment table), used as
	 * the Mine-mode capacity denominator and warning scope.
	 *
	 * @param array<int,array<string,mixed>> $projects Rows with id keys
	 * @return array<int,array<string,mixed>> Raw team rows
	 */
	private function loadInvolvedTeams(int $organizationId, array $projects): array {
		$projectIds = [];
		foreach ($projects as $project) {
			$projectIds[(int)$project['id']] = true;
		}
		if ($projectIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->selectDistinct('team_id')
			->from('organization_project_teams')
			->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('project_id', $qb->createNamedParameter(array_keys($projectIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		$teamIds = [];
		foreach ($rows as $row) {
			$teamIds[(int)$row['team_id']] = true;
		}
		if ($teamIds === []) {
			return [];
		}
		$teamQb = $this->db->getQueryBuilder();
		$rows = $teamQb->select('id', 'organization_id', 'name', 'fte', 'projects_per_fte')
			->from('organization_teams')
			->where($teamQb->expr()->eq('organization_id', $teamQb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($teamQb->expr()->in('id', $teamQb->createNamedParameter(array_keys($teamIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		return array_map([$this, 'mapTeamRow'], $rows);
	}

	/**
	 * Shared eligibility pipeline: derives dates from Deck cards, keeps
	 * capacity-status projects plus in-period historical completions, and
	 * splits off planning gaps.
	 *
	 * @param array<int,array<string,mixed>> $projects Raw project rows with boardId keys
	 * @return array{0:array<int,array<string,mixed>>,1:array<int,array<string,mixed>>} [$eligible, $planningGaps]
	 */
	private function deriveEligibleCapacityProjects(array $projects, DateTimeImmutable $periodStart): array {
		$periodEnd = $periodStart->modify('+41 days');
		$boardIds = [];
		foreach ($projects as $project) {
			if (ctype_digit((string)$project['boardId']) && (int)$project['boardId'] > 0) {
				$boardIds[(int)$project['boardId']] = true;
			}
		}
		$cards = $this->loadCapacityCards(array_keys($boardIds));
		$planningGaps = [];
		$eligible = [];
		foreach ($projects as $project) {
			$dates = $this->deriveCapacityDates($project, $cards[(int)$project['boardId']] ?? []);
			$project = array_merge($project, $dates);
			$actual = $project['actualEnd'];
			$isHistoricalCompletion = $actual !== null && $actual >= $periodStart->format('Y-m-d') && $actual <= $periodEnd->format('Y-m-d');
			if (!in_array((int)$project['status'], self::CAPACITY_STATUSES, true) && !$isHistoricalCompletion) {
				continue;
			}
			if ($project['end'] === null) {
				$planningGaps[] = $this->projectSummary($project);
			}
			$eligible[] = $project;
		}
		return [$eligible, $planningGaps];
	}

	/**
	 * Per-team week summaries for the viewed period, used for All-teams
	 * over-capacity warnings. Planning gaps are irrelevant here.
	 *
	 * @param array<int,array<string,mixed>> $teamRows
	 * @return array<int,array{id:int,name:string,weeks:array<int,array<string,mixed>>}>
	 */
	private function summarizeTeamsInPeriod(int $organizationId, array $teamRows, DateTimeImmutable $periodStart, string $weekStart): array {
		$summaries = [];
		foreach ($teamRows as $row) {
			$team = [
				'id' => (int)$row['id'],
				'organizationId' => $organizationId,
				'name' => (string)$row['name'],
				'fte' => (float)$row['fte'],
				'projectsPerFte' => (float)$row['projects_per_fte'],
			];
			$projects = $this->loadCapacityProjects($organizationId, $team['id']);
			[$eligible] = $this->deriveEligibleCapacityProjects($projects, $periodStart);
			$summary = $this->summarizeCapacity($team, $weekStart, $eligible);
			$summaries[] = ['id' => $team['id'], 'name' => $team['name'], 'weeks' => $summary['weeks']];
		}
		return $summaries;
	}

	/**
	 * Each project's 'end' is its actual completion date when all cards are
	 * done (done flag or done stack), otherwise the planned end, so Ending
	 * lands in the actual done week even when it differs from the plan.
	 *
	 * @param array<string,mixed> $team
	 * @param array<int,array<string,mixed>> $projects
	 */
	public function summarizeCapacity(array $team, string $weekStart, array $projects, array $planningGaps = [], array $unassigned = []): array {
		$monday = $this->normalizeMonday($weekStart);
		$projectsPerFte = (float)($team['projectsPerFte'] ?? $team['projects_per_fte'] ?? 1.0);
		$capacity = round((float)($team['fte'] ?? 0.0) * $projectsPerFte, 2);
		$normalizedProjects = [];
		$normalizedPlanningGaps = $planningGaps;
		foreach ($projects as $project) {
			if ($project['end'] !== null && $project['end'] < $project['start']) {
				$project['end'] = null;
				$project['actualEnd'] = null;
				$normalizedPlanningGaps[] = $this->projectSummary($project);
			}
			$normalizedProjects[] = $project;
		}

		$weeks = [];
		for ($i = 0; $i < 6; $i++) {
			$start = $monday->modify('+' . ($i * 7) . ' days');
			$end = $start->modify('+6 days');
			$starting = $ending = $continuing = $total = 0;
			foreach ($normalizedProjects as $project) {
				$from = $project['start'];
				$to = $project['end'];
				$weekStartDate = $start->format('Y-m-d');
				$weekEndDate = $end->format('Y-m-d');
				if ($from > $weekEndDate || ($to !== null && $to < $weekStartDate)) {
					continue;
				}
				$total++;
				$starts = $from >= $weekStartDate && $from <= $weekEndDate;
				$ends = $to !== null && $to >= $weekStartDate && $to <= $weekEndDate;
				if ($starts) {
					$starting++;
				}
				if ($ends) {
					$ending++;
				}
				if (!$starts && !$ends) {
					$continuing++;
				}
			}
			$remaining = round($capacity - $total, 2);
			$message = 'Within capacity';
			if ($remaining < 0) {
				$message = 'Capacity exceeded';
			} elseif ($total === 0) {
				$message = 'No active projects';
			}
			$weeks[] = [
				'label' => $start->format('o-\WW'),
				'start' => $start->format('Y-m-d'),
				'end' => $end->format('Y-m-d'),
				'starting' => $starting,
				'ending' => $ending,
				'continuing' => $continuing,
				'totalActive' => $total,
				'capacity' => $capacity,
				'remaining' => $remaining,
				'overCapacity' => $remaining < 0,
				'message' => $message,
			];
		}

		return [
			'team' => [
				'id' => (int)$team['id'],
				'organizationId' => (int)$team['organizationId'],
				'name' => (string)$team['name'],
				'fte' => (float)$team['fte'],
				'projectsPerFte' => (float)$team['projectsPerFte'],
				'capacity' => $capacity,
			],
			'period' => [
				'weekStart' => $monday->format('Y-m-d'),
				'weekEnd' => $monday->modify('+41 days')->format('Y-m-d'),
				'weeks' => 6,
			],
			'weeks' => $weeks,
			'planningGaps' => array_values($normalizedPlanningGaps),
			'unassignedProjects' => array_values($unassigned),
		];
	}

	/**
	 * @param array<string,mixed> $row
	 * @return array<string,mixed>
	 */
	private function mapTeamRow(array $row): array {
		$projectsPerFte = (float)($row['projects_per_fte'] ?? $row['projectsPerFte'] ?? 1.0);
		return [
			'id' => (int)$row['id'],
			'organizationId' => (int)($row['organization_id'] ?? $row['organizationId'] ?? 0),
			'name' => (string)$row['name'],
			'fte' => (float)$row['fte'],
			'projects_per_fte' => $projectsPerFte,
			'projectsPerFte' => $projectsPerFte,
		];
	}

	/** @return array<string,mixed>|null */
	private function loadTeam(int $organizationId, int $teamId): ?array {
		$qb = $this->db->getQueryBuilder();
		$row = $qb->select('id', 'organization_id', 'name', 'fte', 'projects_per_fte')
			->from('organization_teams')
			->where($qb->expr()->eq('id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetch();
		if ($row === false) {
			return null;
		}

		return $this->mapTeamRow($row);
	}

	/** @return array<int,array<string,mixed>> */
	private function loadTeams(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('id', 'organization_id', 'name', 'fte', 'projects_per_fte')
			->from('organization_teams')
			->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		return array_map([$this, 'mapTeamRow'], $rows);
	}

	/** @return array<int,array<string,mixed>> */
	private function loadCapacityProjects(int $organizationId, int $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.board_id', 'p.created_at', 'p.desired_start_date', 'p.owner_id', 'p.project_group_gid')
			->from('custom_projects', 'p')
			->innerJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		return $this->mapCapacityProjectRows($rows);
	}

	/** @return array<int,array<string,mixed>> */
	private function loadAllCapacityProjects(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.board_id', 'p.created_at', 'p.desired_start_date', 'p.owner_id', 'p.project_group_gid')
			->from('custom_projects', 'p')
			->innerJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		$projects = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			if (!isset($projects[$id])) {
				$projects[$id] = $this->mapCapacityProjectRow($row);
			}
		}
		return array_values($projects);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function mapCapacityProjectRows(array $rows): array {
		return array_map([$this, 'mapCapacityProjectRow'], $rows);
	}

	/** @return array<string,mixed> */
	private function mapCapacityProjectRow(array $row): array {
		return [
			'id' => (int)$row['id'],
			'name' => (string)$row['name'],
			'status' => (int)$row['status'],
			'boardId' => (string)($row['board_id'] ?? ''),
			'createdAt' => (string)($row['created_at'] ?? ''),
			'desiredStartDate' => $row['desired_start_date'] === null ? null : (string)$row['desired_start_date'],
			'ownerId' => $row['owner_id'] === null ? null : (string)$row['owner_id'],
			'projectGroupGid' => $row['project_group_gid'] === null ? null : (string)$row['project_group_gid'],
		];
	}

	/** @return array<int,true> Project ids assigned to the team */
	private function loadTeamProjectIds(int $organizationId, int $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('project_id')
			->from('organization_project_teams')
			->where($qb->expr()->eq('organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->executeQuery()->fetchAllAssociative();
		$ids = [];
		foreach ($rows as $row) {
			$ids[(int)$row['project_id']] = true;
		}
		return $ids;
	}

	/** @return array<int,array<string,mixed>> */
	private function loadTableProjects(int $organizationId, int $teamId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.board_id', 'p.created_at', 'p.desired_start_date', 'p.actual_start_date', 'p.required_preparation_weeks', 'p.owner_id', 'p.project_group_gid')
			->selectAlias('pt.team_id', 'team_id')
			->from('custom_projects', 'p')
			->innerJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('pt.team_id', $qb->createNamedParameter($teamId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(self::INITIATION_STATUSES, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		return $this->mapTableProjectRows($rows);
	}

	/** @return array<int,array<string,mixed>> */
	private function loadAllTableProjects(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.board_id', 'p.created_at', 'p.desired_start_date', 'p.actual_start_date', 'p.required_preparation_weeks', 'p.owner_id', 'p.project_group_gid')
			->selectAlias('pt.team_id', 'team_id')
			->from('custom_projects', 'p')
			->leftJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id AND pt.organization_id = p.organization_id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(self::INITIATION_STATUSES, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		return $this->mapTableProjectRows($rows);
	}

	/**
	 * @param array<int,array<string,mixed>> $rows
	 * @return array<int,array<string,mixed>>
	 */
	private function mapTableProjectRows(array $rows): array {
		$projects = [];
		foreach ($rows as $row) {
			$id = (int)$row['id'];
			if (!isset($projects[$id])) {
				$projects[$id] = [
					'id' => $id,
					'name' => (string)$row['name'],
					'status' => (int)$row['status'],
					'boardId' => (string)($row['board_id'] ?? ''),
					'createdAt' => (string)($row['created_at'] ?? ''),
					'desiredStartDate' => $row['desired_start_date'] === null ? null : (string)$row['desired_start_date'],
					'actualStartDate' => $row['actual_start_date'] === null ? null : (string)$row['actual_start_date'],
					'requiredPreparationWeeks' => (int)($row['required_preparation_weeks'] ?? 0),
					'ownerId' => $row['owner_id'] === null ? null : (string)$row['owner_id'],
					'projectGroupGid' => $row['project_group_gid'] === null ? null : (string)$row['project_group_gid'],
					'teamId' => $row['team_id'] === null ? null : (int)$row['team_id'],
				];
			}
		}
		return array_values($projects);
	}

	/** @param int[] $boardIds @return array<int,array<int,array<string,mixed>>> */
	private function loadCapacityCards(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('s.board_id', 'c.title', 'c.startdate', 'c.duedate', 'c.done', 'c.stack_id')
			->selectAlias('s.title', 'stack_title')
			->selectAlias('s.order', 'stack_order')
			->from('deck_cards', 'c')
			->innerJoin('c', 'deck_stacks', 's', 'c.stack_id = s.id')
			->where($qb->expr()->in('s.board_id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()->fetchAllAssociative();
		$out = [];
		foreach ($rows as $row) {
			$out[(int)$row['board_id']][] = $row;
		}
		return $out;
	}

	/** @return array<int,array<string,mixed>> */
	private function loadUnassignedProjects(int $organizationId): array {
		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('p.id', 'p.name', 'p.status', 'p.owner_id', 'p.project_group_gid')
			->from('custom_projects', 'p')
			->leftJoin('p', 'organization_project_teams', 'pt', 'pt.project_id = p.id AND pt.organization_id = p.organization_id')
			->where($qb->expr()->eq('p.organization_id', $qb->createNamedParameter($organizationId, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->isNull('pt.project_id'))
			->andWhere($qb->expr()->in('p.status', $qb->createNamedParameter(self::CAPACITY_STATUSES, IQueryBuilder::PARAM_INT_ARRAY)))
			->executeQuery()->fetchAllAssociative();
		return array_map(static function (array $row): array {
			return [
				'id' => (int)$row['id'],
				'name' => (string)$row['name'],
				'status' => (int)$row['status'],
				'ownerId' => $row['owner_id'] === null ? null : (string)$row['owner_id'],
				'projectGroupGid' => $row['project_group_gid'] === null ? null : (string)$row['project_group_gid'],
			];
		}, $rows);
	}

	/** @param array<int,array<string,mixed>> $cards @return array{start:string,end:?string,actualEnd:?string,invalidEnd:bool} */
	private function deriveCapacityDates(array $project, array $cards): array {
		$starts = [];
		$dues = [];
		$dones = [];
		$handoverDue = null;
		$active = count($cards);
		$doneCount = 0;
		$doneStackId = $this->resolveCapacityDoneStackId($cards);
		foreach ($cards as $card) {
			$startDate = $this->dateString($card['startdate'] ?? null);
			if ($startDate !== null) {
				$starts[] = $startDate;
			}

			$dueDate = $this->dateString($card['duedate'] ?? null);
			if ($dueDate !== null) {
				$dues[] = $dueDate;
			}
			$title = strtolower(trim((string)($card['title'] ?? '')));
			if ($title === self::HANDOVER_TITLE && $dueDate !== null) {
				$handoverDue = $dueDate;
			}

			$doneValue = $card['done'] ?? null;
			$hasDoneFlag = $doneValue !== null && trim((string)$doneValue) !== '';
			$inDoneStack = $doneStackId !== null && (int)($card['stack_id'] ?? -1) === $doneStackId;
			if ($hasDoneFlag || $inDoneStack) {
				$doneCount++;
			}
			$doneDate = $this->dateString($doneValue);
			if ($doneDate !== null) {
				$dones[] = $doneDate;
			}
		}

		$created = $this->dateString($project['createdAt']) ?? (new DateTimeImmutable('today'))->format('Y-m-d');
		if ($starts !== []) {
			$start = min($starts);
		} else {
			$start = $this->firstMondayOnOrAfter($created)->format('Y-m-d');
		}
		$actual = null;
		if ($active > 0 && $doneCount === $active) {
			if ($dones !== []) {
				$actual = max($dones);
			} elseif ($dues !== []) {
				// All cards done (flag or done stack) but no parseable done date:
				// fall back to the latest due date so the project still ends.
				$actual = max($dues);
			}
		}
		$planned = $handoverDue;
		if ($planned === null && $dues !== []) {
			$planned = max($dues);
		}
		if ($planned === null && $project['desiredStartDate'] !== null) {
			$planned = $this->dateString($project['desiredStartDate']);
		}

		$invalidEnd = ($actual !== null && $actual < $start) || ($planned !== null && $planned < $start);
		if ($invalidEnd) {
			return [
				'start' => $start,
				'end' => null,
				'actualEnd' => null,
				'invalidEnd' => true,
			];
		}

		return [
			'start' => $start,
			'end' => $actual ?? $planned,
			'actualEnd' => $actual,
			'invalidEnd' => false,
		];
	}

	/**
	 * Resolves the board's done stack from the loaded cards. Only the exact
	 * 'Approved/Done' stack counts, mirroring the established convention in
	 * DeckService/KpiService/OrgOverviewService (`s.title = 'Approved/Done'`
	 * OR `c.done IS NOT NULL`). Unknown/custom stacks are never treated as
	 * done, and there is deliberately no "last stack by order" fallback:
	 * inferring it promoted in-progress work into the 100% bucket.
	 *
	 * @param array<int,array<string,mixed>> $cards
	 */
	private function resolveCapacityDoneStackId(array $cards): ?int {
		foreach ($cards as $card) {
			if (!isset($card['stack_id'])) {
				continue;
			}
			if (trim((string)($card['stack_title'] ?? '')) === 'Approved/Done') {
				return (int)$card['stack_id'];
			}
		}
		return null;
	}

	private function dateString(mixed $value): ?string {
		if (!is_string($value) || trim($value) === '') {
			return null;
		}

		try {
			return (new DateTimeImmutable($value))->format('Y-m-d');
		} catch (\Exception) {
			return null;
		}
	}

	private function projectSummary(array $project): array {
		return ['id' => (int)$project['id'], 'name' => (string)$project['name'], 'status' => (int)$project['status'], 'start' => $project['start'], 'plannedEnd' => $project['end'], 'actualEnd' => $project['actualEnd']];
	}

	private function normalizeMonday(?string $value): DateTimeImmutable {
		if ($value === null) {
			$date = new DateTimeImmutable('today');
		} else {
			$date = new DateTimeImmutable($value);
		}

		return $date->modify('-' . ((int)$date->format('N') - 1) . ' days')->setTime(0, 0);
	}

	/**
	 * Lenient Monday normalization for persisted dates (e.g. desired start
	 * dates). Returns null instead of throwing so one malformed row cannot
	 * fail the whole endpoint.
	 */
	private function tryParseMonday(?string $value): ?DateTimeImmutable {
		if ($value === null || trim($value) === '') {
			return null;
		}
		try {
			$date = new DateTimeImmutable($value);
		} catch (\Exception) {
			return null;
		}

		return $date->modify('-' . ((int)$date->format('N') - 1) . ' days')->setTime(0, 0);
	}

	/**
	 * ISO year-week label (e.g. "2026-W30"). The ISO year prefix removes
	 * year-boundary ambiguity that bare "W30" labels have.
	 */
	private function isoWeekLabel(DateTimeImmutable $date): string {
		return $date->format('o-\WW');
	}

	private function firstMondayOnOrAfter(string $value): DateTimeImmutable {
		$date = new DateTimeImmutable($value);
		$days = (8 - (int)$date->format('N')) % 7;
		return $date->setTime(0, 0)->modify('+' . $days . ' days');
	}

	/**
	 * @param array<int,array{id:int,name:string,boardId:int,totalCards:int,doneCards:int,status?:int}> $projects
	 * @param array<int,array{id:int,name:string,status?:int}> $untrackedProjects
	 * @param array<string,int> $statusCounts
	 */
	public function summarize(array $projects, array $untrackedProjects = [], array $statusCounts = []): array {
		if ($statusCounts === []) {
			$statusCounts = [
				'active' => 0,
				'waiting' => 0,
				'on_hold' => 0,
				'done' => 0,
				'archived' => 0,
			];
			$all = array_merge($projects, $untrackedProjects);
			foreach ($all as $item) {
				if (!isset($item['status'])) {
					continue;
				}
				switch ((int)$item['status']) {
					case ProjectStatus::ACTIVE:
						$statusCounts['active']++;
						break;
					case ProjectStatus::WAITING_ON_CUSTOMER:
						$statusCounts['waiting']++;
						break;
					case ProjectStatus::ON_HOLD:
						$statusCounts['on_hold']++;
						break;
					case ProjectStatus::DONE:
						$statusCounts['done']++;
						break;
					case ProjectStatus::ARCHIVED:
						$statusCounts['archived']++;
						break;
				}
			}
		}

		$buckets = array_map(
			static fn (array $bucket): array => $bucket + ['count' => 0, 'percent' => 0.0],
			self::BUCKETS,
		);

		foreach ($projects as &$project) {
			$total = max(0, (int)$project['totalCards']);
			$done = min($total, max(0, (int)$project['doneCards']));
			$isFullyDone = $total > 0 && $done === $total;
			$completion = $isFullyDone ? 100 : ($total === 0 ? 0 : min(99, (int)round(($done / $total) * 100)));
			$bucketIndex = min(4, intdiv($completion, 25));

			$project['totalCards'] = $total;
			$project['doneCards'] = $done;
			$project['completionPct'] = $completion;
			$project['bucket'] = $buckets[$bucketIndex]['key'];
			$buckets[$bucketIndex]['count']++;
		}
		unset($project);

		$trackedCount = count($projects);
		foreach ($buckets as &$bucket) {
			$bucket['percent'] = $trackedCount === 0
				? 0.0
				: round(($bucket['count'] / $trackedCount) * 100, 1);
		}
		unset($bucket);

		return [
			'totalProjects' => $trackedCount + count($untrackedProjects),
			'trackedProjects' => $trackedCount,
			'untrackedProjects' => $untrackedProjects,
			'statusCounts' => $statusCounts,
			'buckets' => $buckets,
			'projects' => $projects,
		];
	}

	private function normalizeBoardId(?string $boardId): ?int {
		if ($boardId === null || !ctype_digit($boardId) || (int)$boardId < 1) {
			return null;
		}

		return (int)$boardId;
	}

	/** @param int[] $boardIds @return array<int,int> */
	private function getLiveBoardIds(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('id')
			->from('deck_boards')
			->where($qb->expr()->in('id', $qb->createNamedParameter($boardIds, IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->executeQuery()
			->fetchAllAssociative();

		$liveBoardIds = [];
		foreach ($rows as $row) {
			$liveBoardIds[(int)$row['id']] = (int)$row['id'];
		}
		return $liveBoardIds;
	}

	/** @param array<int,int> $boardIds @return array<int,array{total:int,done:int}> */
	private function getCardCounts(array $boardIds): array {
		if ($boardIds === []) {
			return [];
		}

		$qb = $this->db->getQueryBuilder();
		$rows = $qb->select('s.board_id')
			->selectAlias($qb->func()->count('c.id'), 'card_count')
			->from('deck_stacks', 's')
			->innerJoin('s', 'deck_cards', 'c', $qb->expr()->eq('c.stack_id', 's.id'))
			->where($qb->expr()->in('s.board_id', $qb->createNamedParameter(array_values($boardIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($qb->expr()->eq('s.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.deleted_at', $qb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($qb->expr()->eq('c.archived', $qb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			->groupBy('s.board_id')
			->executeQuery()
			->fetchAllAssociative();

		$counts = [];
		foreach ($rows as $row) {
			$counts[(int)$row['board_id']] = ['total' => (int)$row['card_count'], 'done' => 0];
		}

		$doneQb = $this->db->getQueryBuilder();
		$doneRows = $doneQb->select('s.board_id')
			->selectAlias($doneQb->func()->count('c.id'), 'card_count')
			->from('deck_stacks', 's')
			->innerJoin('s', 'deck_cards', 'c', $doneQb->expr()->eq('c.stack_id', 's.id'))
			->where($doneQb->expr()->in('s.board_id', $doneQb->createNamedParameter(array_values($boardIds), IQueryBuilder::PARAM_INT_ARRAY)))
			->andWhere($doneQb->expr()->eq('s.deleted_at', $doneQb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($doneQb->expr()->eq('c.deleted_at', $doneQb->createNamedParameter(0, IQueryBuilder::PARAM_INT)))
			->andWhere($doneQb->expr()->eq('c.archived', $doneQb->createNamedParameter(false, IQueryBuilder::PARAM_BOOL)))
			// Done means an explicit done flag OR the exact 'Approved/Done'
			// stack (same convention as DeckService/KpiService and the
			// table view). No last-stack fallback: unknown stacks are
			// never treated as done.
			->andWhere($doneQb->expr()->orX(
				$doneQb->expr()->isNotNull('c.done'),
				$doneQb->expr()->eq('s.title', $doneQb->createNamedParameter('Approved/Done')),
			))
			->groupBy('s.board_id')
			->executeQuery()
			->fetchAllAssociative();

		foreach ($doneRows as $row) {
			$boardId = (int)$row['board_id'];
			$counts[$boardId] ??= ['total' => 0, 'done' => 0];
			$counts[$boardId]['done'] = (int)$row['card_count'];
		}

		return $counts;
	}
}
