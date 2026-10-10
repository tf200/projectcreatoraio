<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\MemberLoadService;
use OCA\ProjectCreatorAIO\Service\ProjectPortfolioService;
use OCP\IDBConnection;
use PHPUnit\Framework\TestCase;

final class ProjectPortfolioServiceTest extends TestCase {
	private ProjectPortfolioService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ProjectPortfolioService(
			$this->createMock(ProjectMapper::class),
			$this->createMock(IDBConnection::class),
		);
	}

	public function testSummarizeAssignsEveryBoundaryToTheExpectedBucket(): void {
		$projects = [
			$this->project(1, 0, 0),
			$this->project(2, 24, 100),
			$this->project(3, 25, 100),
			$this->project(4, 49, 100),
			$this->project(5, 50, 100),
			$this->project(6, 74, 100),
			$this->project(7, 75, 100),
			$this->project(8, 99, 100),
			$this->project(9, 100, 100),
		];

		$result = $this->service->summarize($projects);

		self::assertSame([2, 2, 2, 2, 1], array_column($result['buckets'], 'count'));
		self::assertSame(['0-24', '0-24', '25-49', '25-49', '50-74', '50-74', '75-99', '75-99', '100'], array_column($result['projects'], 'bucket'));
	}

	public function testSummarizeReportsUntrackedProjectsSeparately(): void {
		$untracked = [['id' => 8, 'name' => 'Missing board']];

		$result = $this->service->summarize([$this->project(1, 1, 4)], $untracked);

		self::assertSame(2, $result['totalProjects']);
		self::assertSame(1, $result['trackedProjects']);
		self::assertSame($untracked, $result['untrackedProjects']);
		self::assertSame(25, $result['projects'][0]['completionPct']);
		self::assertSame(100.0, $result['buckets'][1]['percent']);
	}

	public function testSummarizeTreatsEmptyProjectAsZeroAndCapsInvalidDoneCount(): void {
		$result = $this->service->summarize([
			$this->project(1, 0, 0),
			$this->project(2, 8, 4),
		]);

		self::assertSame(0, $result['projects'][0]['completionPct']);
		self::assertSame(4, $result['projects'][1]['doneCards']);
		self::assertSame(100, $result['projects'][1]['completionPct']);
	}

	public function testSummarizeIncludesStatusCounts(): void {
		$projects = [
			['id' => 1, 'name' => 'P1', 'boardId' => 101, 'totalCards' => 1, 'doneCards' => 0, 'status' => 1],
			['id' => 2, 'name' => 'P2', 'boardId' => 102, 'totalCards' => 2, 'doneCards' => 1, 'status' => 2],
		];
		$untracked = [
			['id' => 3, 'name' => 'P3', 'status' => 3],
			['id' => 4, 'name' => 'P4', 'status' => 0],
		];

		$result = $this->service->summarize($projects, $untracked);
		self::assertSame([
			'active' => 1,
			'waiting' => 1,
			'on_hold' => 1,
			'done' => 0,
			'archived' => 1,
		], $result['statusCounts']);

		$explicit = [
			'active' => 10,
			'waiting' => 5,
			'on_hold' => 2,
			'done' => 20,
			'archived' => 15,
		];
		$resultExplicit = $this->service->summarize($projects, $untracked, $explicit);
		self::assertSame($explicit, $resultExplicit['statusCounts']);
	}

	public function testCapacityUsesInclusiveBoundaries(): void {
		$result = $this->service->summarizeCapacity(
			'2026-09-16',
			[
				['id' => 1, 'name' => 'Starts Monday', 'status' => 1, 'start' => '2026-09-14', 'end' => '2026-09-20', 'actualEnd' => null],
				['id' => 2, 'name' => 'Continues', 'status' => 1, 'start' => '2026-09-01', 'end' => null, 'actualEnd' => null],
			],
		);

		self::assertSame('2026-09-14', $result['period']['weekStart']);
		self::assertCount(6, $result['weeks']);
		self::assertSame(1, $result['weeks'][0]['starting']);
		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
	}

	public function testCapacityNormalizesRequestedDateToItsIsoMonday(): void {
		$result = $this->service->summarizeCapacity(
			'2026-09-20',
			[],
		);

		self::assertSame('2026-09-14', $result['period']['weekStart']);
		self::assertSame('2026-10-25', $result['period']['weekEnd']);
	}

	public function testCapacityKeepsOpenEndedProjectsActiveInAllLaterWeeks(): void {
		$result = $this->service->summarizeCapacity(
			'2026-09-16',
			[$this->capacityProject(1, '2026-09-14', null)],
		);

		self::assertSame([1, 1, 1, 1, 1, 1], array_column($result['weeks'], 'totalActive'));
		self::assertSame([1, 0, 0, 0, 0, 0], array_column($result['weeks'], 'starting'));
		self::assertSame([0, 0, 0, 0, 0, 0], array_column($result['weeks'], 'ending'));
		self::assertSame([0, 1, 1, 1, 1, 1], array_column($result['weeks'], 'continuing'));
	}

	public function testCapacityCountsSameWeekStartAndEndButNotContinuing(): void {
		$result = $this->service->summarizeCapacity(
			'2026-09-14',
			[$this->capacityProject(1, '2026-09-15', '2026-09-17')],
		);

		self::assertSame(1, $result['weeks'][0]['starting']);
		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(0, $result['weeks'][0]['continuing']);
		self::assertSame(1, $result['weeks'][0]['totalActive']);
	}

	public function testCapacityFormatsIsoYearRollover(): void {
		$result = $this->service->summarizeCapacity(
			'2026-12-30',
			[
				$this->capacityProject(1, '2026-12-28', null),
				$this->capacityProject(2, '2026-12-28', null),
			],
		);

		self::assertSame('2026-W53', $result['weeks'][0]['label']);
		self::assertSame('2027-W01', $result['weeks'][1]['label']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
	}

	public function testSummarizeTeamsCountsMembersOfEachTeam(): void {
		$result = $this->service->summarizeTeams(
			[['id' => 7, 'organization_id' => 42, 'name' => 'Design'], ['id' => 8, 'organization_id' => 42, 'name' => 'Build']],
			['ada' => ['teamIds' => [7, 8]], 'bob' => ['teamIds' => [7]]],
		);

		self::assertSame([
			['id' => 7, 'name' => 'Design', 'memberCount' => 2],
			['id' => 8, 'name' => 'Build', 'memberCount' => 1],
		], $result);
	}

	public function testPeopleAreSortedByPeakLoadAndWeeksCountOverloadedAndFullPeople(): void {
		$summary = $this->service->summarizeCapacity('2026-09-14', []);
		$members = [
			'bob' => $this->memberLoad('bob', [1, 1, 1, 0, 0, 0]),
			'ada' => $this->memberLoad('ada', [3, 2, 1, 0, 0, 0]),
			'cy' => $this->memberLoad('cy', [2, 0, 0, 0, 0, 0], [8]),
		];

		$result = $this->service->applyPeople($summary, $members, $this->loadProjects(), $this->teams(), true);

		self::assertSame(['ada', 'cy', 'bob'], array_column($result['people'], 'uid'));
		self::assertSame([3, 2, 1], array_column($result['people'], 'peakLoad'));
		self::assertSame([['id' => 8, 'name' => 'Build']], $result['people'][1]['teams']);
		self::assertSame(['overloaded', 'full', 'free', 'free', 'free', 'free'], array_column($result['people'][0]['weeks'], 'state'));
		self::assertSame([1, 0, 0, 0, 0, 0], array_column($result['weeks'], 'overloadedPeople'));
		self::assertSame([1, 1, 0, 0, 0, 0], array_column($result['weeks'], 'fullPeople'));
		self::assertSame([true, false, false, false, false, false], array_column($result['weeks'], 'overCapacity'));
		self::assertSame([0, 0, 1, 2, 2, 2], array_column($result['weeks'], 'freeSlots'));
		self::assertSame('1 person overloaded', $result['weeks'][0]['message']);
		self::assertSame(2, $result['maxProjectsPerMember']);
	}

	public function testPeopleExplainOverloadsWithTheirProjectsAndTeams(): void {
		$summary = $this->service->summarizeCapacity('2026-09-14', []);

		$result = $this->service->applyPeople($summary, ['ada' => $this->memberLoad('ada', [3, 3, 1, 0, 0, 0])], $this->loadProjects(), $this->teams(), true);

		self::assertSame([[
			'uid' => 'ada',
			'displayName' => 'ADA',
			'peakLoad' => 3,
			'overWeeks' => ['2026-W38', '2026-W39'],
			'projectIds' => [1, 2, 3],
		]], $result['overloadWarnings']);
		self::assertSame([
			['id' => 1, 'name' => 'Project 1', 'teamId' => 7, 'teamName' => 'Design', 'start' => '2026-09-01', 'end' => null],
			['id' => 2, 'name' => 'Project 2', 'teamId' => 7, 'teamName' => 'Design', 'start' => '2026-09-01', 'end' => null],
			['id' => 3, 'name' => 'Project 3', 'teamId' => 8, 'teamName' => 'Build', 'start' => '2026-09-01', 'end' => null],
		], $result['projects']);
	}

	public function testPeopleLeaveFreeSlotsOutAcrossTeams(): void {
		$summary = $this->service->summarizeCapacity('2026-09-14', []);

		$result = $this->service->applyPeople($summary, ['bob' => $this->memberLoad('bob', [2, 2, 2, 2, 2, 2])], $this->loadProjects(), $this->teams(), false);

		self::assertSame([null, null, null, null, null, null], array_column($result['weeks'], 'freeSlots'));
		self::assertSame([], $result['overloadWarnings']);
	}

	public function testPeopleOfATeamWithoutMembersHaveNoFreeSlots(): void {
		$summary = $this->service->summarizeCapacity('2026-09-14', []);

		$result = $this->service->applyPeople($summary, [], [], $this->teams(), true);

		self::assertNull($result['weeks'][0]['freeSlots']);
		self::assertFalse($result['weeks'][0]['overCapacity']);
		self::assertSame([], $result['people']);
		self::assertSame([], $result['projects']);
	}

	public function testIsMemberProjectMatchesOwnerGroupMemberAndNeither(): void {
		self::assertTrue($this->service->isMemberProject(['ownerId' => 'ada', 'projectGroupGid' => 'g1'], 'ada', []));
		self::assertTrue($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => 'g1'], 'ada', ['g1' => true]));
		self::assertFalse($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => 'g1'], 'ada', ['g2' => true]));
		self::assertFalse($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => null], 'ada', []));
		self::assertFalse($this->service->isMemberProject(['ownerId' => null, 'projectGroupGid' => ''], 'ada', ['g1' => true]));
	}

	public function testCapacityDatesPreferActualDoneWeekOverPlannedHandoverWeek(): void {
		$dates = $this->deriveDates(
			$this->datedProject(),
			[
				$this->datedCard('Task A', '2026-09-10', '2026-09-16 10:00:00', 3, 'In progress', 2),
				$this->datedCard('Handover 1', '2026-10-20', null, 9, 'Approved/Done', 5),
			],
		);

		self::assertSame('2026-09-16', $dates['actualEnd']);
		self::assertSame('2026-09-16', $dates['end']);
		self::assertFalse($dates['invalidEnd']);
	}

	public function testCapacityDatesCountDoneStackCardsWithoutFlagsAsEnding(): void {
		$dates = $this->deriveDates(
			$this->datedProject(),
			[
				$this->datedCard('Task A', '2026-09-16', null, 9, 'Approved/Done', 5),
				$this->datedCard('Task B', '2026-09-18', null, 9, 'Approved/Done', 5),
			],
		);

		self::assertSame('2026-09-18', $dates['actualEnd']);
		self::assertSame('2026-09-18', $dates['end']);

		$result = $this->service->summarizeCapacity(
			'2026-09-14',
			[['id' => 1, 'name' => 'Done stack project', 'status' => 1, 'start' => '2026-09-01', 'end' => $dates['end'], 'actualEnd' => $dates['actualEnd']]],
		);

		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(0, $result['weeks'][0]['continuing']);
		self::assertSame(1, $result['weeks'][0]['totalActive']);
	}

	public function testCapacityDatesFallBackToMaxDueWhenDoneHasNoParseableDate(): void {
		$dates = $this->deriveDates(
			$this->datedProject(),
			[
				$this->datedCard('Task A', '2026-09-17', 'done', 3, 'In progress', 2),
				$this->datedCard('Task B', '2026-09-18', 'done', 9, 'Done', 5),
			],
		);

		self::assertSame('2026-09-18', $dates['actualEnd']);
		self::assertSame('2026-09-18', $dates['end']);
	}

	public function testCapacityDatesStayPlannedWhenNotAllCardsAreDone(): void {
		$dates = $this->deriveDates(
			$this->datedProject(),
			[
				$this->datedCard('Task A', '2026-09-16', null, 9, 'Done', 5),
				$this->datedCard('Task B', '2026-10-20', null, 2, 'In progress', 1, '2026-09-01'),
			],
		);

		self::assertNull($dates['actualEnd']);
		self::assertSame('2026-10-20', $dates['end']);
	}

	public function testBuildTableOverviewCalculatesCountdownsBucketsAndScheduleIssues(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22'); // 2026-W26
		$requestedMonday = new \DateTimeImmutable('2026-07-27'); // W31

		$projects = [
			[
				'id' => 1,
				'name' => 'De Rozenhof',
				'status' => 1,
				'boardId' => '101',
				'createdAt' => '2026-05-01',
				'desiredStartDate' => '2026-08-17', // W34
				'actualStartDate' => '2026-08-24', // W35
				'requiredPreparationWeeks' => 4,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p1',
				'teamId' => 7,
			],
			[
				'id' => 2,
				'name' => 'Kerkstraat',
				'status' => 1,
				'boardId' => '102',
				'createdAt' => '2026-05-01',
				'desiredStartDate' => '2026-08-03', // W32
				'actualStartDate' => null,
				'requiredPreparationWeeks' => 4,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p2',
				'teamId' => 7,
			],
			[
				'id' => 3,
				'name' => 'Havenkwartier',
				'status' => 1,
				'boardId' => '103',
				'createdAt' => '2026-04-01',
				'desiredStartDate' => '2026-07-27', // W31
				'requiredPreparationWeeks' => 0,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p3',
				'teamId' => 7,
			],
		];

		$cardsByBoard = [
			101 => [
				$this->datedCard('Handover 1', '2026-07-19', null, 1, 'In progress', 1),
				$this->datedCard('Task 1', '2026-06-01', '2026-06-02', 2, 'Done', 2),
			],
			102 => [
				$this->datedCard('Handover 1', '2026-07-12', null, 1, 'In progress', 1),
				$this->datedCard('Archived dummy', '2026-07-15', null, 2, 'Done', 2),
			],
			103 => [
				$this->datedCard('Handover 1', '2026-07-20', '2026-07-24', 2, 'Done', 2),
			],
		];

		$capacitySummary = [
			'period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6],
			'team' => ['id' => 7, 'organizationId' => 42, 'name' => 'Team Alpha'],
			'weeks' => [],
		];

		$result = $this->service->buildTableOverview($projects, $cardsByBoard, $capacitySummary, $currentMonday, $requestedMonday);

		self::assertSame(3, $result['totalProjects']);
		self::assertSame(0, $result['planningGapCount']);
		self::assertSame('2026-W26', $result['currentWeek']['label']);
		self::assertSame(2026, $result['currentWeek']['isoYear']);

		$rows = $result['projects'];

		// Row 1: De Rozenhof
		self::assertSame('De Rozenhof', $rows[0]['name']);
		self::assertSame('2026-W29', $rows[0]['expectedOrAchievedLabel']);
		self::assertSame('2026-W30', $rows[0]['startPrepWeek']);
		self::assertSame('4 weeks', $rows[0]['startPrepCountdown']);
		self::assertSame('2026-W34', $rows[0]['minExecutionStartWeek']);
		self::assertSame('2026-W34', $rows[0]['desiredStartWeek']);
		self::assertSame('8 weeks', $rows[0]['desiredCountdown']);
		self::assertSame('2026-08-24', $rows[0]['actualStartDate']);
		self::assertSame('2026-W35', $rows[0]['actualStartWeek']);
		self::assertFalse($rows[0]['planningGap']['hasGap']);
		self::assertSame('None', $rows[0]['planningGap']['display']);
		self::assertSame(1, $rows[0]['openCards']);

		// Row 2: Kerkstraat
		self::assertSame('Kerkstraat', $rows[1]['name']);
		self::assertSame('2026-W28', $rows[1]['expectedOrAchievedLabel']);
		self::assertSame('2026-W29', $rows[1]['startPrepWeek']);
		self::assertSame('3 weeks', $rows[1]['startPrepCountdown']);
		self::assertSame('2026-W33', $rows[1]['minExecutionStartWeek']);
		self::assertSame('2026-W32', $rows[1]['desiredStartWeek']);
		self::assertSame('6 weeks', $rows[1]['desiredCountdown']);
		self::assertNull($rows[1]['actualStartDate']);
		self::assertSame('—', $rows[1]['actualStartWeek']);
		self::assertFalse($rows[1]['planningGap']['hasGap']);
		self::assertSame('None', $rows[1]['planningGap']['display']);
		self::assertSame('Desired start is before minimum execution start', $result['planningConflicts'][0]['note']);

		// Row 3: Havenkwartier
		self::assertSame('Havenkwartier', $rows[2]['name']);
		self::assertTrue($rows[2]['isCompleted']);
		self::assertSame('100% reached 2026-W30', $rows[2]['expectedOrAchievedLabel']);
		self::assertSame('2026-W31', $rows[2]['startPrepWeek']);
		self::assertSame('—', $rows[2]['startPrepCountdown']);
		self::assertTrue($rows[2]['isLeadingDesiredWeek']);
		self::assertSame('5 weeks', $rows[2]['desiredCountdown']);
		self::assertFalse($rows[2]['planningGap']['hasGap']);
		self::assertSame('None', $rows[2]['planningGap']['display']);
		self::assertSame(0, $rows[2]['openCards']);
	}

	public function testSummarizeNeverPromotesIncompleteProjectToHundred(): void {
		// 199/200 rounds to 100% with round() but must stay in 75-99.
		$result = $this->service->summarize([$this->project(1, 199, 200)]);

		self::assertSame(99, $result['projects'][0]['completionPct']);
		self::assertSame('75-99', $result['projects'][0]['bucket']);
		self::assertSame(0, $result['buckets'][4]['count']);
		self::assertSame(1, $result['buckets'][3]['count']);
	}

	public function testBuildTableOverviewCapsRoundedCompletionBelowHundred(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22');
		$requestedMonday = new \DateTimeImmutable('2026-07-27');
		$cards = [];
		for ($i = 0; $i < 199; $i++) {
			$cards[] = $this->datedCard('Task ' . $i, '2026-07-19', '2026-06-02', 2, 'Approved/Done', 2);
		}
		$cards[] = $this->datedCard('Open task', '2026-07-19', null, 1, 'In progress', 1);
		$result = $this->service->buildTableOverview(
			[[
				'id' => 9,
				'name' => 'Almost done',
				'status' => 1,
				'boardId' => '109',
				'createdAt' => '2026-05-01',
				'desiredStartDate' => null,
				'requiredPreparationWeeks' => 0,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p9',
				'teamId' => 7,
			]],
			[109 => $cards],
			['period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6], 'weeks' => []],
			$currentMonday,
			$requestedMonday,
		);

		self::assertSame(99, $result['projects'][0]['completionPct']);
		self::assertSame('75-99', $result['projects'][0]['bucket']);
		self::assertFalse($result['projects'][0]['isCompleted']);
		self::assertSame(0, $result['buckets'][5]['count']);
	}

	public function testBuildTableOverviewIgnoresUnknownDoneStackTitles(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22');
		$requestedMonday = new \DateTimeImmutable('2026-07-27');
		$result = $this->service->buildTableOverview(
			[[
				'id' => 10,
				'name' => 'Custom stack',
				'status' => 1,
				'boardId' => '110',
				'createdAt' => '2026-05-01',
				'desiredStartDate' => null,
				'requiredPreparationWeeks' => 0,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p10',
				'teamId' => 7,
			]],
			[110 => [
				$this->datedCard('Task A', '2026-07-19', null, 5, 'Klaar', 9),
				$this->datedCard('Task B', '2026-07-19', null, 5, 'Klaar', 9),
			]],
			['period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6], 'weeks' => []],
			$currentMonday,
			$requestedMonday,
		);

		self::assertSame(0, $result['projects'][0]['doneCards']);
		self::assertSame(0, $result['projects'][0]['completionPct']);
		self::assertFalse($result['projects'][0]['isCompleted']);
	}

	public function testBuildTableOverviewCountsExactApprovedDoneStackWithoutFlag(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22');
		$requestedMonday = new \DateTimeImmutable('2026-07-27');
		$result = $this->service->buildTableOverview(
			[[
				'id' => 11,
				'name' => 'Approved stack',
				'status' => 1,
				'boardId' => '111',
				'createdAt' => '2026-05-01',
				'desiredStartDate' => null,
				'requiredPreparationWeeks' => 0,
				'ownerId' => 'admin',
				'projectGroupGid' => 'p11',
				'teamId' => 7,
			]],
			[111 => [
				$this->datedCard('Task A', '2026-07-19', null, 9, 'Approved/Done', 5),
				$this->datedCard('Task B', '2026-07-19', null, 1, 'In progress', 1),
			]],
			['period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6], 'weeks' => []],
			$currentMonday,
			$requestedMonday,
		);

		self::assertSame(1, $result['projects'][0]['doneCards']);
		self::assertSame(50, $result['projects'][0]['completionPct']);
	}

	public function testBuildTableOverviewDegradesMalformedDesiredDatePerRow(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22');
		$requestedMonday = new \DateTimeImmutable('2026-07-27');
		$result = $this->service->buildTableOverview(
			[
				[
					'id' => 12,
					'name' => 'Bad date',
					'status' => 1,
					'boardId' => '112',
					'createdAt' => '2026-05-01',
					'desiredStartDate' => 'not-a-date',
					'requiredPreparationWeeks' => 0,
					'ownerId' => 'admin',
					'projectGroupGid' => 'p12',
					'teamId' => 7,
				],
				[
					'id' => 13,
					'name' => 'Good date',
					'status' => 1,
					'boardId' => '113',
					'createdAt' => '2026-05-01',
					'desiredStartDate' => '2026-08-17',
					'requiredPreparationWeeks' => 0,
					'ownerId' => 'admin',
					'projectGroupGid' => 'p13',
					'teamId' => 7,
				],
			],
			[
				112 => [$this->datedCard('Handover 1', '2026-07-19', null, 1, 'In progress', 1)],
				113 => [$this->datedCard('Handover 1', '2026-07-19', null, 1, 'In progress', 1)],
			],
			['period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6], 'weeks' => []],
			$currentMonday,
			$requestedMonday,
		);

		self::assertSame('—', $result['projects'][0]['desiredStartWeek']);
		self::assertSame('—', $result['projects'][0]['desiredCountdown']);
		self::assertFalse($result['projects'][0]['isLeadingDesiredWeek']);
		self::assertSame('2026-W34', $result['projects'][1]['desiredStartWeek']);
	}

	public function testBuildTableOverviewLeadingDesiredWeekRequiresCompletionBeforeDesired(): void {
		$currentMonday = new \DateTimeImmutable('2026-06-22');
		$requestedMonday = new \DateTimeImmutable('2026-07-27');
		$capacity = ['period' => ['weekStart' => '2026-07-27', 'weekEnd' => '2026-09-06', 'weeks' => 6], 'weeks' => []];
		$project = [
			'id' => 14,
			'name' => 'Late completion',
			'status' => 1,
			'boardId' => '114',
			'createdAt' => '2026-05-01',
			'desiredStartDate' => '2026-07-27',
			'requiredPreparationWeeks' => 0,
			'ownerId' => 'admin',
			'projectGroupGid' => 'p14',
			'teamId' => 7,
		];
		// Completed 2026-08-10 (Monday 2026-W33), after desired 2026-W31.
		$result = $this->service->buildTableOverview(
			[$project],
			[114 => [$this->datedCard('Handover 1', '2026-07-19', '2026-08-10', 9, 'Approved/Done', 5)]],
			$capacity,
			$currentMonday,
			$requestedMonday,
		);

		self::assertTrue($result['projects'][0]['isCompleted']);
		self::assertFalse($result['projects'][0]['isLeadingDesiredWeek']);

		// Incomplete project with a desired date is never leading.
		$open = $this->service->buildTableOverview(
			[$project],
			[114 => [$this->datedCard('Handover 1', '2026-09-19', null, 1, 'In progress', 1)]],
			$capacity,
			$currentMonday,
			$requestedMonday,
		);
		self::assertFalse($open['projects'][0]['isCompleted']);
		self::assertFalse($open['projects'][0]['isLeadingDesiredWeek']);
	}

	public function testCapacityCountsProjectsFromActualStartUntilHandover(): void {
		$eligible = $this->invokePrivate('deriveEligibleCapacityProjects', [[
			$this->executionProject(1, 1, '2026-09-21', 2, null),
			$this->executionProject(2, 1, null, 6, null),
			$this->executionProject(3, 4, '2026-09-14', 1, '2026-09-30'),
		]]);

		self::assertSame([1, 3], array_column($eligible, 'id'));
		// Two weeks on site from Monday end on the second Sunday.
		self::assertSame(['2026-09-21', '2026-10-04', null], [$eligible[0]['start'], $eligible[0]['end'], $eligible[0]['actualEnd']]);
		// A recorded handover wins over start + weeks.
		self::assertSame(['2026-09-14', '2026-09-30', '2026-09-30'], [$eligible[1]['start'], $eligible[1]['end'], $eligible[1]['actualEnd']]);

		$result = $this->service->summarizeCapacity('2026-09-14', $eligible);
		self::assertSame([1, 2, 2, 0, 0, 0], array_column($result['weeks'], 'totalActive'));
		self::assertSame([1, 1, 2, 0, 0, 0], array_map(static fn (array $w): int => $w['starting'] + $w['ending'], $result['weeks']));
	}

	public function testCapacitySkipsClosedProjectsWithoutRecordedHandoverAndEndsBeforeStart(): void {
		$eligible = $this->invokePrivate('deriveEligibleCapacityProjects', [[
			$this->executionProject(1, 0, '2026-09-21', null, null),
			$this->executionProject(2, 0, '2026-09-21', 2, null),
			$this->executionProject(3, 1, '2026-09-21', null, null),
			$this->executionProject(4, 4, '2026-09-21', 2, null),
			$this->executionProject(5, 4, '2026-09-21', 2, '2026-09-25'),
			$this->executionProject(6, 1, '2026-09-21', null, '2026-09-01'),
		]]);

		self::assertSame([3, 5], array_column($eligible, 'id'));
		self::assertNull($eligible[0]['end']);
		self::assertSame('2026-09-25', $eligible[1]['end']);
	}

	public function testUnstartedLiveProjectsAreListedToScheduleByDesiredStart(): void {
		$later = $this->executionProject(1, 1, null, 4, null);
		$later['desiredStartDate'] = '2027-03-01';
		$sooner = $this->executionProject(2, 2, null, 4, null);
		$sooner['desiredStartDate'] = '2027-01-04';
		$undated = $this->executionProject(3, 3, null, null, null);

		$toSchedule = $this->invokePrivate('findProjectsToSchedule', [[
			$later,
			$undated,
			$sooner,
			$this->executionProject(4, 1, '2026-09-21', 2, null),
			$this->executionProject(5, 4, null, 2, null),
		]]);

		self::assertSame([2, 1, 3], array_column($toSchedule, 'id'));
		self::assertSame('2027-01-04', $toSchedule[0]['desiredStartDate']);
	}

	public function testStartedProjectsWithoutHandoverPlanAreScheduleIssues(): void {
		$issues = $this->invokePrivate('findExecutionIssues', [[
			$this->executionProject(1, 1, '2026-09-21', null, null),
			$this->executionProject(2, 1, '2026-09-21', 2, null),
			$this->executionProject(3, 1, '2026-09-21', null, '2026-09-01'),
			$this->executionProject(4, 4, '2026-09-21', null, null),
		]]);

		self::assertSame([1, 3], array_column($issues, 'projectId'));
		self::assertSame('Handover before actual start', $issues[1]['note']);
	}

	private function invokePrivate(string $name, array $args): mixed {
		$method = new \ReflectionMethod(ProjectPortfolioService::class, $name);
		return $method->invokeArgs($this->service, $args);
	}

	private function executionProject(int $id, int $status, ?string $actualStart, ?int $weeks, ?string $handover): array {
		return [
			'id' => $id,
			'name' => 'Project ' . $id,
			'status' => $status,
			'desiredStartDate' => null,
			'actualStartDate' => $actualStart,
			'actualHandoverDate' => $handover,
			'executionWeeks' => $weeks,
		];
	}

	private function deriveDates(array $project, array $cards): array {
		$method = new \ReflectionMethod(ProjectPortfolioService::class, 'deriveCapacityDates');
		return $method->invoke($this->service, $project, $cards);
	}

	private function datedProject(): array {
		return ['id' => 1, 'name' => 'Project 1', 'createdAt' => '2026-09-01', 'desiredStartDate' => null];
	}

	private function datedCard(string $title, ?string $due, mixed $done, int $stackId, string $stackTitle, int $stackOrder, ?string $start = null): array {
		return [
			'title' => $title,
			'startdate' => $start,
			'duedate' => $due,
			'done' => $done,
			'stack_id' => $stackId,
			'stack_title' => $stackTitle,
			'stack_order' => $stackOrder,
		];
	}

	/** @return array<int,array<string,mixed>> */
	private function teams(): array {
		return [['id' => 7, 'organizationId' => 42, 'name' => 'Design'], ['id' => 8, 'organizationId' => 42, 'name' => 'Build']];
	}

	/** @return array<int,array<string,mixed>> Team projects keyed by id, as MemberLoadService::getLoads() returns them */
	private function loadProjects(): array {
		$projects = [];
		foreach ([1 => 7, 2 => 7, 3 => 8] as $id => $teamId) {
			$projects[$id] = ['id' => $id, 'name' => 'Project ' . $id, 'teamId' => $teamId, 'start' => '2026-09-01', 'end' => null];
		}
		return $projects;
	}

	/**
	 * @param int[] $loads
	 * @param int[] $teamIds
	 */
	private function memberLoad(string $uid, array $loads, array $teamIds = [7]): array {
		return [
			'uid' => $uid,
			'displayName' => strtoupper($uid),
			'teamIds' => $teamIds,
			'weeks' => array_map(static fn (int $load): array => [
				'load' => $load,
				'projectIds' => $load === 0 ? [] : range(1, $load),
				'state' => MemberLoadService::state($load),
			], $loads),
		];
	}

	private function capacityProject(int $id, string $start, ?string $end): array {
		return [
			'id' => $id,
			'name' => 'Project ' . $id,
			'status' => 1,
			'start' => $start,
			'end' => $end,
			'actualEnd' => $end,
		];
	}

	private function project(int $id, int $done, int $total): array {
		return [
			'id' => $id,
			'name' => 'Project ' . $id,
			'boardId' => $id + 100,
			'totalCards' => $total,
			'doneCards' => $done,
		];
	}
}
