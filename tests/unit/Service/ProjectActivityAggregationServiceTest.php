<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use DateTime;
use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEvent;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Service\NativeDeckActivityReader;
use OCA\ProjectCreatorAIO\Service\ProjectActivityAggregationService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use PHPUnit\Framework\TestCase;

final class ProjectActivityAggregationServiceTest extends TestCase {
	public function testMergesCustomAndNativeEventsInTimestampOrder(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$mapper->method('findForProjectBefore')->willReturn([
			$this->customEvent(7, ProjectActivityService::EVENT_PROJECT_UPDATED, 'internal', '2026-08-29T12:00:00+00:00'),
		]);
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->expects($this->once())->method('read')->willReturn([
			'events' => [$this->nativeEvent(50, 'card_comment_create', 9, 1788001200)],
			'hasMore' => false,
			'authoritative' => true,
		]);

		$page = (new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 20);

		$this->assertSame([7, 'deck-native-50'], array_column($page['events'], 'id'));
		$this->assertFalse($page['hasMore']);
		$this->assertNull($page['nextCursor']);
		$this->assertArrayNotHasKey('_sortTimestamp', $page['events'][0]);
	}

	public function testNativeActivityReplacesCustomDeckEvents(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$custom = $this->customEvent(8, ProjectActivityService::EVENT_DECK_CARD_CREATED, 'deck', '2026-08-29T12:00:00+00:00');
		$custom->setPayloadArray(['cardId' => 9, 'cardTitle' => 'Plan']);
		$mapper->method('findForProjectBefore')->willReturn([$custom]);
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->method('read')->willReturn([
			'events' => [$this->nativeEvent(51, 'card_create', 9, (new DateTime('2026-08-29T12:00:05+00:00'))->getTimestamp())],
			'hasMore' => false,
			'authoritative' => true,
		]);

		$page = (new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 20, 'deck');

		$this->assertCount(1, $page['events']);
		$this->assertSame('deck-native-51', $page['events'][0]['id']);
	}

	public function testReturnsCursorWhenMoreEventsExist(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$mapper->method('findForProjectBefore')->willReturn([
			$this->customEvent(3, ProjectActivityService::EVENT_PROJECT_UPDATED, 'internal', '2026-08-29T12:00:00+00:00'),
			$this->customEvent(2, ProjectActivityService::EVENT_PROJECT_CREATED, 'internal', '2026-08-29T11:00:00+00:00'),
		]);
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->method('read')->willReturn(['events' => [], 'hasMore' => false, 'authoritative' => false]);

		$page = (new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 1, 'internal');

		$this->assertTrue($page['hasMore']);
		$this->assertNotNull($page['nextCursor']);
		$this->assertCount(1, $page['events']);
	}

	public function testLargeLegacyOffsetStopsPagination(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$mapper->expects($this->never())->method('findForProjectBefore');
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->expects($this->never())->method('read');

		$this->expectException(\InvalidArgumentException::class);
		(new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 20, null, null, 500);
	}

	public function testMalformedCursorRestartsSafely(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$mapper->expects($this->once())
			->method('findForProjectBefore')
			->with(42, 21, 'internal', null, null)
			->willReturn([]);
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->expects($this->never())->method('read');
		$cursor = rtrim(strtr(base64_encode(json_encode([
			'version' => 1,
			'projectId' => 42,
			'userId' => 'member1',
			'source' => 'internal',
			'custom' => ['timestamp' => 'not-a-date', 'id' => 5],
			'native' => null,
		], JSON_THROW_ON_ERROR)), '+/', '-_'), '=');

		$page = (new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 20, 'internal', $cursor);

		$this->assertSame([], $page['events']);
		$this->assertFalse($page['hasMore']);
	}

	public function testCustomDeckEventsRemainWhenNativeReaderFails(): void {
		$mapper = $this->createMock(ProjectActivityEventMapper::class);
		$mapper->method('findForProjectBefore')->willReturn([
			$this->customEvent(8, ProjectActivityService::EVENT_DECK_CARD_CREATED, 'deck', '2026-08-29T12:00:00+00:00'),
		]);
		$reader = $this->createMock(NativeDeckActivityReader::class);
		$reader->method('read')->willReturn(['events' => [], 'hasMore' => false, 'authoritative' => false]);

		$page = (new ProjectActivityAggregationService($mapper, $reader))
			->getActivity($this->project(), 'member1', 20, 'deck');

		$this->assertSame([8], array_column($page['events'], 'id'));
	}

	private function project(): Project {
		$project = new Project();
		$project->setId(42);
		$project->setBoardId('10');
		return $project;
	}

	private function customEvent(int $id, string $type, string $source, string $occurredAt): ProjectActivityEvent {
		$event = new ProjectActivityEvent();
		$event->setId($id);
		$event->setProjectId(42);
		$event->setEventType($type);
		$event->setSource($source);
		$event->setOccurredAt(new DateTime($occurredAt));
		$event->setPayloadArray([]);
		return $event;
	}

	/**
	 * @return array<string, mixed>
	 */
	private function nativeEvent(int $id, string $subject, int $cardId, int $timestamp): array {
		return [
			'id' => 'deck-native-' . $id,
			'projectId' => null,
			'actorUid' => 'member1',
			'actorDisplayName' => 'Member One',
			'eventType' => 'deck_' . $subject,
			'source' => 'deck',
			'payload' => [
				'nativeActivity' => true,
				'nativeSubject' => $subject,
				'cardId' => $cardId,
				'cardTitle' => 'Plan',
			],
			'occurredAt' => (new DateTime('@' . $timestamp))->format('c'),
			'_sortTimestamp' => $timestamp,
			'_sortId' => $id,
			'_stream' => 'native',
		];
	}
}
