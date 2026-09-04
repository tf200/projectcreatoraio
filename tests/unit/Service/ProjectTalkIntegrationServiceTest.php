<?php

declare(strict_types=1);

namespace OCA\Talk {
    class Manager
    {
        public function __construct(private readonly object $room)
        {
        }

        public function getRoomByToken(string $token): object
        {
            return $this->room;
        }
    }
}

namespace OCA\Talk\Service {
    use OCP\IUser;

    class ParticipantService
    {
        public array $calls = [];

        public function addUsers(object $room, array $participants, ?IUser $addedBy = null): void
        {
            $this->calls[] = [
                'room' => $room,
                'participants' => $participants,
                'addedBy' => $addedBy,
            ];
        }
    }

    class RoomService
    {
        public array $descriptions = [];

        public function setDescription(object $room, string $description): void
        {
            $this->descriptions[] = [
                'room' => $room,
                'description' => $description,
            ];
        }
    }
}

namespace OCA\Talk\Chat {
    class Message
    {
        public function __construct(
            private readonly string $actorDisplayName,
            private readonly string $message,
            private readonly string $messageType,
            private readonly bool $visibility,
        ) {
        }

        public function getActorDisplayName(): string
        {
            return $this->actorDisplayName;
        }

        public function getMessage(): string
        {
            return $this->message;
        }

        public function getMessageType(): string
        {
            return $this->messageType;
        }

        public function getVisibility(): bool
        {
            return $this->visibility;
        }
    }

    class MessageParser
    {
        public function createMessage(object $room, mixed $participant, object $comment, object $l10n): Message
        {
            return new Message(
                $comment->getTestActorDisplayName(),
                $comment->getTestMessage(),
                $comment->getTestMessageType(),
                $comment->getTestVisibility(),
            );
        }

        public function parseMessage(Message $message): void
        {
        }
    }
}

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service {

use DateTimeImmutable;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCA\Talk\Manager;
use OCA\Talk\Chat\MessageParser;
use OCA\Talk\Service\ParticipantService;
use OCA\Talk\Service\RoomService;
use OCP\Comments\ICommentsManager;
use OCP\Files\File;
use OCP\Files\Folder;
use OCP\Files\IRootFolder;
use OCP\IServerContainer;
use OCP\IL10N;
use OCP\IURLGenerator;
use OCP\IUser;
use OCP\IUserManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\Share\IManager as IShareManager;
use OCP\Share\IShare;
use OCP\Talk\IBroker;
use OCP\Talk\IConversation;
use OCP\Talk\IConversationOptions;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

class ProjectTalkIntegrationServiceTest extends TestCase
{
    private IBroker&MockObject $talkBroker;
    private IServerContainer&MockObject $serverContainer;
    private IUserManager&MockObject $userManager;
    private IURLGenerator&MockObject $urlGenerator;
    private IRootFolder&MockObject $rootFolder;
    private IShareManager&MockObject $shareManager;
    private ICommentsManager&MockObject $commentsManager;
    private IL10NFactory&MockObject $l10nFactory;
    private LoggerInterface&MockObject $logger;

    protected function setUp(): void
    {
        parent::setUp();

        $this->talkBroker = $this->createMock(IBroker::class);
        $this->serverContainer = $this->createMock(IServerContainer::class);
        $this->userManager = $this->createMock(IUserManager::class);
        $this->urlGenerator = $this->createMock(IURLGenerator::class);
        $this->rootFolder = $this->createMock(IRootFolder::class);
        $this->shareManager = $this->createMock(IShareManager::class);
        $this->commentsManager = $this->createMock(ICommentsManager::class);
        $this->l10nFactory = $this->createMock(IL10NFactory::class);
        $this->logger = $this->createMock(LoggerInterface::class);
    }

    public function testCreateProjectConversationSeedsNonOwnerMembers(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
            'getDisplayName' => 'Project Owner',
        ]);
        $member = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'member-1',
            'getDisplayName' => 'Member One',
        ]);
        $conversation = $this->createConfiguredMock(IConversation::class, [
            'getId' => 'room-token',
            'getAbsoluteUrl' => 'https://cloud.example.test/call/room-token',
        ]);
        $options = $this->createMock(IConversationOptions::class);
        $options->method('setPublic')->willReturnSelf();

        $room = new \stdClass();
        $participantService = new ParticipantService();
        $manager = new Manager($room);

        $this->talkBroker->expects($this->once())
            ->method('newConversationOptions')
            ->willReturn($options);
        $this->talkBroker->expects($this->once())
            ->method('createConversation')
            ->with('New HQ Build - Chat', [$owner], $options)
            ->willReturn($conversation);

        $this->userManager->expects($this->once())
            ->method('get')
            ->with('member-1')
            ->willReturn($member);

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $result = $service->createProjectConversation('New HQ Build', $owner, ['owner', 'member-1']);

        $this->assertSame('room-token', $result['token']);
        $this->assertSame('https://cloud.example.test/call/room-token', $result['url']);
        $this->assertCount(1, $participantService->calls);
        $this->assertSame($room, $participantService->calls[0]['room']);
        $this->assertSame($owner, $participantService->calls[0]['addedBy']);
        $this->assertSame([[
            'actorType' => 'users',
            'actorId' => 'member-1',
            'displayName' => 'Member One',
            'participantType' => 3,
        ]], $participantService->calls[0]['participants']);
    }

    public function testCreateProjectDirectConversationCreatesPrivateRoomAndSeedsParticipants(): void
    {
        $user1 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'alice',
            'getDisplayName' => 'Alice Doe',
        ]);
        $user2 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'bob',
            'getDisplayName' => 'Bob Smith',
        ]);
        $conversation = $this->createConfiguredMock(IConversation::class, [
            'getId' => 'direct-token-123',
            'getAbsoluteUrl' => 'https://cloud.example.test/call/direct-token-123',
        ]);
        $options = $this->createMock(IConversationOptions::class);
        $options->expects($this->never())->method('setPublic');

        $room = new \stdClass();
        $participantService = new ParticipantService();
        $roomService = new RoomService();
        $manager = new Manager($room);

        $this->talkBroker->expects($this->once())
            ->method('newConversationOptions')
            ->willReturn($options);
        $this->talkBroker->expects($this->once())
            ->method('createConversation')
            ->with('Phoenix Project - Alice Doe & Bob Smith', [$user1, $user2], $options)
            ->willReturn($conversation);

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService, $roomService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    'OCA\\Talk\\Service\\RoomService' => $roomService,
                    default => throw new \RuntimeException("Unexpected service lookup: $serviceClass"),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $result = $service->createProjectDirectConversation('Phoenix Project', 42, $user1, $user2);

        $this->assertSame('direct-token-123', $result['token']);
        $this->assertSame('https://cloud.example.test/call/direct-token-123', $result['url']);

        $this->assertCount(1, $participantService->calls);
        $this->assertSame($room, $participantService->calls[0]['room']);
        $this->assertSame($user1, $participantService->calls[0]['addedBy']);
        $this->assertSame([
            [
                'actorType' => 'users',
                'actorId' => 'alice',
                'displayName' => 'Alice Doe',
                'participantType' => 3,
            ],
            [
                'actorType' => 'users',
                'actorId' => 'bob',
                'displayName' => 'Bob Smith',
                'participantType' => 3,
            ],
        ], $participantService->calls[0]['participants']);

        $this->assertCount(1, $roomService->descriptions);
        $this->assertSame('Direct project chat for Phoenix Project between Alice Doe and Bob Smith.', $roomService->descriptions[0]['description']);
    }

    public function testCreateProjectDirectConversationTruncatesLongRoomName(): void
    {
        $user1 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'u1',
            'getDisplayName' => str_repeat('A', 150),
        ]);
        $user2 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'u2',
            'getDisplayName' => str_repeat('B', 150),
        ]);
        $conversation = $this->createConfiguredMock(IConversation::class, [
            'getId' => 'token-long',
            'getAbsoluteUrl' => 'https://cloud.example.test/call/token-long',
        ]);
        $options = $this->createMock(IConversationOptions::class);

        $room = new \stdClass();
        $participantService = new ParticipantService();
        $manager = new Manager($room);

        $this->talkBroker->expects($this->once())
            ->method('newConversationOptions')
            ->willReturn($options);
        $this->talkBroker->expects($this->once())
            ->method('createConversation')
            ->with($this->callback(static function (string $name): bool {
                return mb_strlen($name) === 255 && str_ends_with($name, '...');
            }), [$user1, $user2], $options)
            ->willReturn($conversation);

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException("Unexpected: $serviceClass"),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $result = $service->createProjectDirectConversation('Very Long Project Name', 1, $user1, $user2);
        $this->assertSame('token-long', $result['token']);
    }

    public function testCreateProjectDirectConversationDeletesRoomOnError(): void
    {
        $user1 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'u1',
            'getDisplayName' => 'User One',
        ]);
        $user2 = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'u2',
            'getDisplayName' => 'User Two',
        ]);
        $conversation = $this->createConfiguredMock(IConversation::class, [
            'getId' => 'fail-token',
            'getAbsoluteUrl' => 'https://cloud.example.test/call/fail-token',
        ]);
        $options = $this->createMock(IConversationOptions::class);

        $this->talkBroker->expects($this->once())
            ->method('newConversationOptions')
            ->willReturn($options);
        $this->talkBroker->expects($this->once())
            ->method('createConversation')
            ->willReturn($conversation);

        // Talk Manager fails to look up room
        $this->serverContainer->method('get')
            ->willThrowException(new \RuntimeException('Talk DB failure'));

        $this->talkBroker->expects($this->once())
            ->method('deleteConversation')
            ->with('fail-token');

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Talk DB failure');
        $service->createProjectDirectConversation('Test Project', 1, $user1, $user2);
    }

    public function testDeleteConversationsDeletesAllTokens(): void
    {
        $this->talkBroker->expects($this->exactly(2))
            ->method('deleteConversation')
            ->willReturnCallback(function (string $token) {
                $this->assertContains($token, ['tok-1', 'tok-2']);
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $service->deleteConversations(['tok-1', 'tok-2', '']);
    }

    public function testBuildConversationUrlReturnsNullWithoutToken(): void
    {
        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $this->assertNull($service->buildConversationUrl(null));
        $this->assertNull($service->buildConversationUrl(''));
    }

    public function testShareFileInConversationCreatesRoomShareAndPostsChatMessage(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $room = new \stdClass();
        $manager = new Manager($room);
        $participantService = new ParticipantService();

        $folder = $this->createMock(Folder::class);
        $file = $this->createConfiguredMock(File::class, [
            'getId' => 42,
            'getMimeType' => 'application/octet-stream',
        ]);
        $share = $this->createMock(IShare::class);

        foreach (['setNodeId', 'setShareTime', 'setSharedBy', 'setNode', 'setShareType', 'setSharedWith', 'setPermissions'] as $method) {
            $share->method($method)->willReturnSelf();
        }
        $share->method('getId')->willReturn('321');

        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($folder);
        $folder->expects($this->once())
            ->method('getFirstNodeById')
            ->with(42)
            ->willReturn($file);

        $this->shareManager->expects($this->once())
            ->method('getSharesBy')
            ->with('owner', IShare::TYPE_ROOM, $file, false, -1, 0)
            ->willReturn([]);
        $this->shareManager->expects($this->once())
            ->method('newShare')
            ->willReturn($share);
        $this->shareManager->expects($this->once())
            ->method('createShare')
            ->with($share)
            ->willReturn($share);
        $this->shareManager->expects($this->never())
            ->method('deleteShare');

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $service->shareFileInConversation('room-token', 42, $owner);
    }

    public function testShareFileInConversationSkipsIfShareAlreadyExists(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $room = new \stdClass();
        $manager = new Manager($room);
        $participantService = new ParticipantService();

        $folder = $this->createMock(Folder::class);
        $file = $this->createConfiguredMock(File::class, [
            'getId' => 42,
            'getMimeType' => 'application/octet-stream',
        ]);
        $existingShare = $this->createMock(IShare::class);
        $existingShare->method('getSharedWith')->willReturn('room-token');

        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($folder);
        $folder->expects($this->once())
            ->method('getFirstNodeById')
            ->with(42)
            ->willReturn($file);

        $this->shareManager->expects($this->once())
            ->method('getSharesBy')
            ->with('owner', IShare::TYPE_ROOM, $file, false, -1, 0)
            ->willReturn([$existingShare]);
        $this->shareManager->expects($this->never())->method('newShare');
        $this->shareManager->expects($this->never())->method('createShare');
        $this->shareManager->expects($this->never())->method('deleteShare');

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $created = $service->shareFileInConversation('room-token', 42, $owner);

        $this->assertFalse($created);
    }

    public function testShareFileInConversationPropagatesCreateShareFailure(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $room = new \stdClass();
        $manager = new Manager($room);
        $participantService = new ParticipantService();

        $folder = $this->createMock(Folder::class);
        $file = $this->createConfiguredMock(File::class, [
            'getId' => 42,
            'getMimeType' => 'application/octet-stream',
        ]);
        $share = $this->createMock(IShare::class);

        foreach (['setNodeId', 'setShareTime', 'setSharedBy', 'setNode', 'setShareType', 'setSharedWith', 'setPermissions'] as $method) {
            $share->method($method)->willReturnSelf();
        }
        $share->method('getId')->willReturn('321');

        $this->rootFolder->method('getUserFolder')->with('owner')->willReturn($folder);
        $folder->method('getFirstNodeById')->with(42)->willReturn($file);

        $this->shareManager->method('getSharesBy')
            ->with('owner', IShare::TYPE_ROOM, $file, false, -1, 0)
            ->willReturn([]);
        $this->shareManager->method('newShare')->willReturn($share);
        $this->shareManager->method('createShare')
            ->with($share)
            ->willThrowException(new \RuntimeException('boom'));

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('boom');

        $service->shareFileInConversation('room-token', 42, $owner);
    }

    public function testShareFileInConversationFallsBackToFolderAndSkipsInvalidNames(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $room = new \stdClass();
        $manager = new Manager($room);
        $participantService = new ParticipantService();

        $userFolder = $this->createMock(Folder::class);
        $projectFolder = $this->createMock(Folder::class);
        $invalidFile = $this->createMock(File::class);
        $fallbackFile = $this->createConfiguredMock(File::class, [
            'getId' => 77,
            'getName' => 'Project X.whiteboard',
            'getMimeType' => 'application/octet-stream',
        ]);
        $share = $this->createMock(IShare::class);

        foreach (['setNodeId', 'setShareTime', 'setSharedBy', 'setNode', 'setShareType', 'setSharedWith', 'setPermissions'] as $method) {
            $share->method($method)->willReturnSelf();
        }
        $share->method('getId')->willReturn('654');
        $invalidFile->method('getId')->willReturn(13);
        $invalidFile->method('getName')->willReturn(null);

        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($userFolder);
        $userFolder->expects($this->once())
            ->method('getFirstNodeById')
            ->with(42)
            ->willReturn(null);
        $this->rootFolder->expects($this->exactly(2))
            ->method('getById')
            ->willReturnCallback(static function (int $id) use ($projectFolder): array {
                return match ($id) {
                    42 => [],
                    901 => [$projectFolder],
                    default => [],
                };
            });
        $projectFolder->expects($this->once())
            ->method('getDirectoryListing')
            ->willReturn([$invalidFile, $fallbackFile]);

        $this->shareManager->expects($this->once())
            ->method('getSharesBy')
            ->with('owner', IShare::TYPE_ROOM, $fallbackFile, false, -1, 0)
            ->willReturn([]);
        $this->shareManager->expects($this->once())
            ->method('newShare')
            ->willReturn($share);
        $this->shareManager->expects($this->once())
            ->method('createShare')
            ->with($share)
            ->willReturn($share);

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $created = $service->shareFileInConversation('room-token', 42, $owner, 'Projects/Project X', 'Project X', 99, 901);

        $this->assertTrue($created);
    }

    public function testShareFileInConversationFallsBackToGlobalFileId(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $room = new \stdClass();
        $manager = new Manager($room);
        $participantService = new ParticipantService();

        $userFolder = $this->createMock(Folder::class);
        $globalFile = $this->createConfiguredMock(File::class, [
            'getId' => 42,
            'getMimeType' => 'application/octet-stream',
        ]);
        $share = $this->createMock(IShare::class);

        foreach (['setNodeId', 'setShareTime', 'setSharedBy', 'setNode', 'setShareType', 'setSharedWith', 'setPermissions'] as $method) {
            $share->method($method)->willReturnSelf();
        }
        $share->method('getId')->willReturn('777');

        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($userFolder);
        $userFolder->expects($this->once())
            ->method('getFirstNodeById')
            ->with(42)
            ->willReturn(null);
        $this->rootFolder->expects($this->once())
            ->method('getById')
            ->with(42)
            ->willReturn([$globalFile]);

        $this->shareManager->expects($this->once())
            ->method('getSharesBy')
            ->with('owner', IShare::TYPE_ROOM, $globalFile, false, -1, 0)
            ->willReturn([]);
        $this->shareManager->expects($this->once())
            ->method('newShare')
            ->willReturn($share);
        $this->shareManager->expects($this->once())
            ->method('createShare')
            ->with($share)
            ->willReturn($share);

        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $participantService): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Service\\ParticipantService' => $participantService,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $created = $service->shareFileInConversation('room-token', 42, $owner, 'Projects/Project X', 'Project X', 99, 901);

        $this->assertTrue($created);
    }

    public function testShareFileInConversationFailsWhenNoFileResolutionPathWorks(): void
    {
        $owner = $this->createConfiguredMock(IUser::class, [
            'getUID' => 'owner',
        ]);
        $userFolder = $this->createMock(Folder::class);
        $manager = new Manager(new \stdClass());

        $this->serverContainer->method('get')
            ->with('OCA\\Talk\\Manager')
            ->willReturn($manager);

        $this->rootFolder->expects($this->once())
            ->method('getUserFolder')
            ->with('owner')
            ->willReturn($userFolder);
        $userFolder->expects($this->once())
            ->method('getFirstNodeById')
            ->with(42)
            ->willReturn(null);
        $this->rootFolder->expects($this->exactly(2))
            ->method('getById')
            ->willReturn([]);
        $userFolder->expects($this->once())
            ->method('get')
            ->with('Projects/Project X')
            ->willThrowException(new \OCP\Files\NotFoundException());

        $service = new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('File 42 is not accessible for user "owner".');

        $service->shareFileInConversation('room-token', 42, $owner, 'Projects/Project X', 'Project X', 99, 901);
    }

    public function testGetConversationMessagesOnlyQueriesHumanContentVerbsAndKeepsAttachments(): void
    {
        $comments = [
            $this->createChatComment(105, 'Alice', 'Hello', 'comment', true),
            $this->createChatComment(104, 'Bob', 'report.pdf', 'comment', true),
            $this->createChatComment(103, 'Alice', 'voice-message.ogg', 'voice-message', true),
            $this->createChatComment(102, 'Bob', 'Hidden', 'comment', false),
        ];
        $service = $this->createChatMessageService(new class {
            public function getId(): int
            {
                return 42;
            }
        });

        $this->commentsManager->expects($this->once())
            ->method('getCommentsWithVerbForObjectSinceComment')
            ->with('chat', '42', ['comment', 'object_shared'], 0, 'desc', 50)
            ->willReturn($comments);

        $result = $service->getConversationMessages('room-token', 10);

        $this->assertSame([105, 104, 103], array_column($result['messages'], 'id'));
        $this->assertSame(['comment', 'comment', 'voice-message'], array_column($result['messages'], 'messageType'));
        $this->assertFalse($result['hasMore']);
        $this->assertSame(102, $result['nextOffset']);
    }

    public function testGetConversationMessagesUsesNextSourceOffsetWithoutDuplicates(): void
    {
        $service = $this->createChatMessageService(new class {
            public function getId(): int
            {
                return 42;
            }
        });

        $this->commentsManager->expects($this->exactly(2))
            ->method('getCommentsWithVerbForObjectSinceComment')
            ->willReturnCallback(function (
                string $objectType,
                string $objectId,
                array $verbs,
                int $offset,
                string $sortDirection,
                int $limit,
            ): array {
                $this->assertSame('chat', $objectType);
                $this->assertSame('42', $objectId);
                $this->assertSame(['comment', 'object_shared'], $verbs);
                $this->assertSame('desc', $sortDirection);
                $this->assertSame(50, $limit);

                return match ($offset) {
                    0 => [
                        $this->createChatComment(10, 'Alice', 'Newest'),
                        $this->createChatComment(9, 'Bob', 'Middle'),
                        $this->createChatComment(8, 'Alice', 'Oldest'),
                    ],
                    9 => [$this->createChatComment(8, 'Alice', 'Oldest')],
                    default => [],
                };
            });

        $firstPage = $service->getConversationMessages('room-token', 2);
        $secondPage = $service->getConversationMessages('room-token', 2, $firstPage['nextOffset']);

        $this->assertSame([10, 9], array_column($firstPage['messages'], 'id'));
        $this->assertTrue($firstPage['hasMore']);
        $this->assertSame(9, $firstPage['nextOffset']);
        $this->assertSame([8], array_column($secondPage['messages'], 'id'));
        $this->assertFalse($secondPage['hasMore']);
        $this->assertSame(8, $secondPage['nextOffset']);
    }

    private function createChatMessageService(object $room): ProjectTalkIntegrationService
    {
        $manager = new Manager($room);
        $messageParser = new MessageParser();
        $l10n = $this->createMock(IL10N::class);

        $this->talkBroker->method('hasBackend')->willReturn(true);
        $this->l10nFactory->method('get')->with('spreed')->willReturn($l10n);
        $this->serverContainer->method('get')
            ->willReturnCallback(static function (string $serviceClass) use ($manager, $messageParser): object {
                return match ($serviceClass) {
                    'OCA\\Talk\\Manager' => $manager,
                    'OCA\\Talk\\Chat\\MessageParser' => $messageParser,
                    default => throw new \RuntimeException('Unexpected service lookup'),
                };
            });

        return new ProjectTalkIntegrationService(
            $this->talkBroker,
            $this->serverContainer,
            $this->userManager,
            $this->urlGenerator,
            $this->rootFolder,
            $this->shareManager,
            $this->commentsManager,
            $this->l10nFactory,
            $this->logger,
        );
    }

    private function createChatComment(
        int $id,
        string $actorDisplayName,
        string $message,
        string $messageType = 'comment',
        bool $visibility = true,
    ): object {
        return new class ($id, $actorDisplayName, $message, $messageType, $visibility) {
            public function __construct(
                private readonly int $id,
                private readonly string $actorDisplayName,
                private readonly string $message,
                private readonly string $messageType,
                private readonly bool $visibility,
            ) {
            }

            public function getId(): string
            {
                return (string)$this->id;
            }

            public function getCreationDateTime(): DateTimeImmutable
            {
                return new DateTimeImmutable('@' . $this->id);
            }

            public function getTestActorDisplayName(): string
            {
                return $this->actorDisplayName;
            }

            public function getTestMessage(): string
            {
                return $this->message;
            }

            public function getTestMessageType(): string
            {
                return $this->messageType;
            }

            public function getTestVisibility(): bool
            {
                return $this->visibility;
            }
        };
    }
}
}
