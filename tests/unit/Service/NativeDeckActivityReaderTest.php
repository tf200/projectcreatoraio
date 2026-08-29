<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\NoPermissionException;
use OCA\Deck\Service\PermissionService;
use OCA\ProjectCreatorAIO\Service\NativeDeckActivityReader;
use OCP\IDBConnection;
use OCP\IUserManager;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class NativeDeckActivityReaderTest extends TestCase {
	public function testUnavailableReaderReturnsEmptyPage(): void {
		$reader = new NativeDeckActivityReader(
			null,
			null,
			null,
			null,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertFalse($reader->isAvailable());
		$this->assertSame([
			'events' => [],
			'hasMore' => false,
			'authoritative' => false,
		], $reader->read(42, 'member1', 20));
	}

	public function testActivityFromCardChunksIsMergedGlobally(): void {
		$userManager = $this->createMock(IUserManager::class);
		$userManager->method('getDisplayName')->willReturn('Member One');
		$reader = new class($userManager, $this->createMock(LoggerInterface::class)) extends NativeDeckActivityReader {
			public function __construct(IUserManager $userManager, LoggerInterface $logger) {
				parent::__construct(null, null, null, null, $userManager, $logger);
			}

			public function queryForTest(array $cardIds): array {
				return $this->queryActivity(42, $cardIds, 'member1', 3, null);
			}

			protected function queryObjectActivity(string $objectType, array $objectIds, string $userId, int $limit, ?array $cursor): array {
				if ($objectType === 'deck_board') {
					return ['rows' => [$this->row(2, 100, 'deck_board', 42)], 'hasMore' => false];
				}
				if ($objectIds[0] === 1) {
					return ['rows' => [
						$this->row(5, 300, 'deck_card', 1),
						$this->row(3, 100, 'deck_card', 2),
					], 'hasMore' => false];
				}
				return ['rows' => [$this->row(4, 200, 'deck_card', 401)], 'hasMore' => true];
			}

			private function row(int $id, int $timestamp, string $objectType, int $objectId): array {
				return [
					'activity_id' => $id,
					'timestamp' => $timestamp,
					'user' => 'member1',
					'subject' => $objectType === 'deck_card' ? 'card_update_title' : 'board_update_title',
					'subjectparams' => '{}',
					'object_type' => $objectType,
					'object_id' => $objectId,
					'object_name' => 'Object ' . $objectId,
				];
			}
		};

		$page = $reader->queryForTest(range(1, 401));

		$this->assertSame(['deck-native-5', 'deck-native-4', 'deck-native-3'], array_column($page['events'], 'id'));
		$this->assertTrue($page['hasMore']);
		$this->assertTrue($page['authoritative']);
	}

	public function testBoardPermissionDenialHidesDeckActivityAuthoritatively(): void {
		$permissionService = $this->createMock(PermissionService::class);
		$permissionService->method('checkPermission')->willThrowException(new NoPermissionException('Denied'));
		$reader = new NativeDeckActivityReader(
			$this->createMock(IDBConnection::class),
			$this->createMock(CardMapper::class),
			$this->createMock(BoardMapper::class),
			$permissionService,
			$this->createMock(IUserManager::class),
			$this->createMock(LoggerInterface::class),
		);

		$this->assertSame([
			'events' => [],
			'hasMore' => false,
			'authoritative' => true,
		], $reader->read(42, 'member1', 20));
	}
}
