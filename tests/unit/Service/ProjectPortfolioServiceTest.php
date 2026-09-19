<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
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

	public function testCapacityUsesInclusiveBoundariesAndRoundsCapacity(): void {
		$result = $this->service->summarizeCapacity(
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.5, 'projectsPerFte' => 2.333],
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
		self::assertSame(3.5, $result['team']['capacity']);
	}

	public function testCapacityNormalizesRequestedDateToItsIsoMonday(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
			'2026-09-20',
			[],
		);

		self::assertSame('2026-09-14', $result['period']['weekStart']);
		self::assertSame('2026-10-25', $result['period']['weekEnd']);
	}

	public function testCapacityKeepsOpenEndedProjectsActiveInAllLaterWeeks(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
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
			$this->team(2, 1),
			'2026-09-14',
			[$this->capacityProject(1, '2026-09-15', '2026-09-17')],
		);

		self::assertSame(1, $result['weeks'][0]['starting']);
		self::assertSame(1, $result['weeks'][0]['ending']);
		self::assertSame(0, $result['weeks'][0]['continuing']);
		self::assertSame(1, $result['weeks'][0]['totalActive']);
	}

	public function testCapacityFormatsIsoYearRolloverAndOverCapacity(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(1, 1),
			'2026-12-30',
			[
				$this->capacityProject(1, '2026-12-28', null),
				$this->capacityProject(2, '2026-12-28', null),
			],
		);

		self::assertSame('2026-W53', $result['weeks'][0]['label']);
		self::assertSame('2027-W01', $result['weeks'][1]['label']);
		self::assertTrue($result['weeks'][0]['overCapacity']);
		self::assertSame(-1.0, $result['weeks'][0]['remaining']);
	}

	public function testCapacityTurnsAnEndBeforeStartIntoAPlanningGap(): void {
		$result = $this->service->summarizeCapacity(
			$this->team(4, 1),
			'2026-09-14',
			[$this->capacityProject(7, '2026-09-20', '2026-09-19')],
		);

		self::assertCount(1, $result['planningGaps']);
		self::assertSame([1, 1, 1, 1, 1, 1], array_column($result['weeks'], 'totalActive'));
	}

	public function testSummarizeTeamsListsPerTeamCapacity(): void {
		$result = $this->service->summarizeTeams([
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.5, 'projectsPerFte' => 2.333],
			['id' => 8, 'organizationId' => 42, 'name' => 'Build', 'fte' => 2.0, 'projectsPerFte' => 1.0],
		]);

		self::assertSame([
			['id' => 7, 'name' => 'Design', 'capacity' => 3.5],
			['id' => 8, 'name' => 'Build', 'capacity' => 2.0],
		], $result);
	}

	public function testBuildAllTeamsRowSumsCapacities(): void {
		$row = $this->service->buildAllTeamsRow([
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.5, 'projectsPerFte' => 2.333],
			['id' => 8, 'organizationId' => 42, 'name' => 'Build', 'fte' => 2.0, 'projectsPerFte' => 1.0],
		], 42);

		self::assertSame(0, $row['id']);
		self::assertSame(42, $row['organizationId']);
		self::assertSame('All teams', $row['name']);
		self::assertSame(5.5, $row['fte'] * $row['projectsPerFte']);
	}

	public function testCapacityAllTeamsRowAggregatesLoadAcrossTeams(): void {
		$row = $this->service->buildAllTeamsRow([
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.0, 'projectsPerFte' => 1.0],
			['id' => 8, 'organizationId' => 42, 'name' => 'Build', 'fte' => 1.0, 'projectsPerFte' => 1.0],
		], 42);
		$result = $this->service->summarizeCapacity(
			$row,
			'2026-09-14',
			[
				['id' => 1, 'name' => 'Design project', 'status' => 1, 'start' => '2026-09-14', 'end' => '2026-09-20', 'actualEnd' => null],
				['id' => 2, 'name' => 'Build project', 'status' => 1, 'start' => '2026-09-01', 'end' => null, 'actualEnd' => null],
			],
		);

		self::assertSame(2.0, $result['team']['capacity']);
		self::assertSame(2, $result['weeks'][0]['totalActive']);
		self::assertFalse($result['weeks'][0]['overCapacity']);
	}

	public function testBuildTeamWarningsListsOnlyOverCapacityTeams(): void {
		$warnings = $this->service->buildTeamWarnings([
			['id' => 7, 'name' => 'Design', 'weeks' => [
				['label' => '2026-W38', 'overCapacity' => true],
				['label' => '2026-W39', 'overCapacity' => false],
			]],
			['id' => 8, 'name' => 'Build', 'weeks' => [
				['label' => '2026-W38', 'overCapacity' => false],
			]],
		]);

		self::assertSame([['id' => 7, 'name' => 'Design', 'overWeeks' => ['2026-W38']]], $warnings);
	}

	public function testDedupeCapacityGapsKeepsFirstProjectEntry(): void {
		$gaps = [
			['id' => 1, 'name' => 'First'],
			['id' => 1, 'name' => 'Duplicate'],
			['id' => 2, 'name' => 'Second'],
		];

		self::assertSame([['id' => 1, 'name' => 'First'], ['id' => 2, 'name' => 'Second']], $this->service->dedupeCapacityGaps($gaps));
	}

	public function testIsMemberProjectMatchesOwnerGroupMemberAndNeither(): void {
		self::assertTrue($this->service->isMemberProject(['ownerId' => 'ada', 'projectGroupGid' => 'g1'], 'ada', []));
		self::assertTrue($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => 'g1'], 'ada', ['g1' => true]));
		self::assertFalse($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => 'g1'], 'ada', ['g2' => true]));
		self::assertFalse($this->service->isMemberProject(['ownerId' => 'bob', 'projectGroupGid' => null], 'ada', []));
		self::assertFalse($this->service->isMemberProject(['ownerId' => null, 'projectGroupGid' => ''], 'ada', ['g1' => true]));
	}

	public function testBuildAllTeamsRowUsesCustomNameAndSumsInvolvedTeamsOnly(): void {
		$row = $this->service->buildAllTeamsRow([
			['id' => 7, 'organizationId' => 42, 'name' => 'Design', 'fte' => 1.5, 'projectsPerFte' => 2.0],
		], 42, 'My teams');

		self::assertSame('My teams', $row['name']);
		self::assertSame(3.0, $row['fte'] * $row['projectsPerFte']);

		$result = $this->service->summarizeCapacity(
			$row,
			'2026-09-14',
			[$this->capacityProject(1, '2026-09-14', '2026-09-20')],
		);

		self::assertSame('My teams', $result['team']['name']);
		self::assertSame(3.0, $result['team']['capacity']);
		self::assertSame(1, $result['weeks'][0]['totalActive']);
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
			$this->team(1, 1),
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

	public function testBuildTableOverviewCalculatesCountdownsBucketsAndPlanningGapsMatchingMockup(): void {
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
			'team' => ['id' => 7, 'name' => 'Team Alpha', 'fte' => 4.0, 'projectsPerFte' => 2.0, 'capacity' => 8.0],
			'weeks' => [],
		];

		$result = $this->service->buildTableOverview($projects, $cardsByBoard, $capacitySummary, $currentMonday, $requestedMonday);

		self::assertSame(3, $result['totalProjects']);
		self::assertSame(1, $result['planningGapCount']);
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
		self::assertTrue($rows[1]['planningGap']['hasGap']);
		self::assertSame('2 weeks · 2026-W32-2026-W33', $rows[1]['planningGap']['display']);

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

	private function team(float $fte, float $projectsPerFte): array {
		return [
			'id' => 7,
			'organizationId' => 42,
			'name' => 'Design',
			'fte' => $fte,
			'projectsPerFte' => $projectsPerFte,
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
