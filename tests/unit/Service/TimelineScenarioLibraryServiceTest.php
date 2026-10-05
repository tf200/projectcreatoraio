<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\TimelineScenario;
use OCA\ProjectCreatorAIO\Db\TimelineScenarioMapper;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioLibraryService;
use OCA\ProjectCreatorAIO\Service\TimelineScenarioService;
use OCP\AppFramework\OCS\OCSForbiddenException;
use OCP\AppFramework\OCS\OCSNotFoundException;
use OCP\IUser;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;

final class TimelineScenarioLibraryServiceTest extends TestCase
{
	private TimelineScenarioMapper $mapper;
	private TimelineScenarioService $scenarios;
	private TimelineScenarioLibraryService $library;
	private Project $project;

	protected function setUp(): void
	{
		$this->mapper = $this->createMock(TimelineScenarioMapper::class);
		$this->scenarios = $this->createMock(TimelineScenarioService::class);
		$users = $this->createMock(IUserManager::class);
		$users->method('getDisplayName')->willReturnCallback(static fn (string $uid): ?string => $uid === 'alice' ? 'Alice' : null);
		$this->library = new TimelineScenarioLibraryService($this->mapper, $this->scenarios, $users);

		$this->project = new Project();
		$this->project->setId(7);
		$this->project->setOwnerId('owner');
	}

	public function testSavesTheChangesAsTheServerUnderstandsThem(): void
	{
		$this->scenarios->method('simulate')->willReturn(['changes' => [
			['type' => 'delay', 'taskId' => 1, 'label' => 'Permits', 'days' => 14],
			['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 2, 'predecessorLabel' => 'Permits', 'successorLabel' => 'VO', 'days' => 5],
		]]);
		$this->mapper->method('countByProject')->willReturn(0);
		$this->mapper->expects($this->once())->method('insert')->willReturnCallback(static function (TimelineScenario $scenario): TimelineScenario {
			$scenario->setId(3);
			return $scenario;
		});

		$saved = $this->library->save($this->project, $this->user('alice'), '  Permits slip  ', [['type' => 'delay', 'taskId' => '1', 'days' => '14']]);

		$this->assertSame(3, $saved['id']);
		$this->assertSame('Permits slip', $saved['name']);
		$this->assertSame([
			['type' => 'delay', 'taskId' => 1, 'days' => 14],
			['type' => 'overlap', 'predecessorId' => 1, 'successorId' => 2, 'days' => 5],
		], $saved['changes']);
		$this->assertSame('Alice', $saved['createdByDisplayName']);
		$this->assertTrue($saved['canEdit']);
	}

	/** @dataProvider invalidSaves */
	public function testRejectsInvalidSaves(mixed $name, array $changes, int $existing, string $message): void
	{
		$this->scenarios->method('simulate')->willReturn(['changes' => $changes]);
		$this->mapper->method('countByProject')->willReturn($existing);
		$this->mapper->expects($this->never())->method('insert');

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage($message);
		$this->library->save($this->project, $this->user('alice'), $name, $changes);
	}

	public static function invalidSaves(): array
	{
		$delay = [['type' => 'delay', 'taskId' => 1, 'days' => 7]];
		return [
			'no name' => ['  ', $delay, 0, 'Give the scenario a name'],
			'name not text' => [['x'], $delay, 0, 'Give the scenario a name'],
			'name too long' => [str_repeat('a', 121), $delay, 0, 'Give the scenario a name'],
			'no changes' => ['Empty', [], 0, 'add a change before saving'],
			'too many' => ['One more', $delay, 50, 'at most 50 saved scenarios'],
		];
	}

	public function testOnlyTheAuthorOrProjectOwnerCanChangeAScenario(): void
	{
		$this->mapper->method('findInProject')->willReturnCallback(fn (): TimelineScenario => $this->stored('alice'));
		$this->mapper->method('update')->willReturnArgument(0);
		$this->mapper->expects($this->exactly(2))->method('delete');

		$this->assertSame('Renamed', $this->library->update($this->project, $this->user('alice'), 3, 'Renamed', null)['name']);
		$this->library->delete($this->project, $this->user('alice'), 3);
		$this->library->delete($this->project, $this->user('owner'), 3);

		$this->expectException(OCSForbiddenException::class);
		$this->library->delete($this->project, $this->user('bob'), 3);
	}

	public function testScenarioFromAnotherProjectIsNotFound(): void
	{
		$this->mapper->method('findInProject')->willReturn(null);

		$this->expectException(OCSNotFoundException::class);
		$this->library->update($this->project, $this->user('alice'), 99, 'Name', null);
	}

	public function testListShowsEachScenarioNextToTheLivePlan(): void
	{
		$first = $this->stored('alice', 3);
		$second = $this->stored('bob', 4);
		$this->mapper->method('findByProject')->willReturn([$first, $second]);
		$this->scenarios->expects($this->once())->method('compare')
			->with($this->project, [3 => $first->getChangeList(), 4 => $second->getChangeList()])
			->willReturn([
				'livePlan' => ['minimumStartDate' => '2026-03-06'],
				'scenarios' => [
					3 => ['impact' => ['minimumStartDate' => '2026-03-20'], 'error' => null],
					4 => ['impact' => null, 'error' => '"Permits" is already completed and cannot be changed'],
				],
			]);

		$list = $this->library->list($this->project, $this->user('bob'));

		$this->assertSame('2026-03-06', $list['livePlan']['minimumStartDate']);
		$this->assertSame('2026-03-20', $list['scenarios'][0]['impact']['minimumStartDate']);
		$this->assertFalse($list['scenarios'][0]['canEdit']);
		$this->assertNull($list['scenarios'][1]['impact']);
		$this->assertStringContainsString('already completed', $list['scenarios'][1]['error']);
		$this->assertTrue($list['scenarios'][1]['canEdit']);
	}

	private function stored(string $author, int $id = 3): TimelineScenario
	{
		$scenario = new TimelineScenario();
		$scenario->setId($id);
		$scenario->setProjectId(7);
		$scenario->setName('Scenario ' . $id);
		$scenario->setChanges(json_encode([['type' => 'delay', 'taskId' => 1, 'days' => $id]]));
		$scenario->setCreatedBy($author);
		$scenario->setCreatedAt(new DateTime('2026-10-01'));
		$scenario->setUpdatedAt(new DateTime('2026-10-01'));
		return $scenario;
	}

	private function user(string $uid): IUser
	{
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($uid);
		return $user;
	}
}
