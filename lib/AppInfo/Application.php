<?php

namespace OCA\ProjectCreatorAIO\AppInfo;

use OCA\ProjectCreatorAIO\BackgroundJob\DetectStaleProjectsJob;
use OCA\ProjectCreatorAIO\BackgroundJob\GenerateProjectExportJob;
use OCA\ProjectCreatorAIO\BackgroundJob\ProcessPendingFileProcessingJob;
use OCA\ProjectCreatorAIO\BackgroundJob\PurgeArchivedProjectsJob;
use OCA\ProjectCreatorAIO\BackgroundJob\PurgeOldExportsJob;
use OCA\ProjectCreatorAIO\BackgroundJob\SendProjectDigestJob;
use OCA\ProjectCreatorAIO\Db\PrivateFolderLinkMapper;
use OCA\ProjectCreatorAIO\Dashboard\ProjectsWidget;
use OCA\Deck\Event\AclCreatedEvent;
use OCA\Deck\Event\AclDeletedEvent;
use OCA\Deck\Event\AclUpdatedEvent;
use OCA\Deck\Event\BoardCreatedEvent;
use OCA\Deck\Event\BoardDeletedEvent;
use OCA\Deck\Event\BoardUpdatedEvent;
use OCA\Deck\Event\CardCreatedEvent;
use OCA\Deck\Event\CardDeletedEvent;
use OCA\Deck\Event\CardUpdatedEvent;
use OCA\Deck\Service\BoardService;
use OCA\Deck\Service\CardService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\PermissionService;
use OCA\Deck\Service\StackService;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Listener\DeckEventListener;
use OCA\ProjectCreatorAIO\Listener\FileEventListener;
use OCA\ProjectCreatorAIO\Listener\FileProcessingWrittenListener;
use OCA\ProjectCreatorAIO\Listener\TalkEventListener;
use OCA\ProjectCreatorAIO\Listener\WhiteboardWrittenListener;
use OCA\ProjectCreatorAIO\Notification\Notifier;
use OCA\ProjectCreatorAIO\Service\DeckDefaultCardsService;
use OCA\ProjectCreatorAIO\Service\FileProcessingPipelineService;
use OCA\ProjectCreatorAIO\Service\ProjectDeckActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectDigestService;
use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCA\ProjectCreatorAIO\Service\ProjectRetentionService;
use OCA\ProjectCreatorAIO\Service\TimelinePlanningService;
use OCA\Talk\Events\AttendeeRemovedEvent;
use OCA\Talk\Events\AttendeesAddedEvent;
use OCA\Talk\Events\CallEndedEvent;
use OCA\Talk\Events\CallStartedEvent;
use OCA\Talk\Events\ChatMessageSentEvent;
use OCA\Talk\Events\ReactionAddedEvent;
use OCA\Talk\Events\ReactionRemovedEvent;
use OCA\Talk\Events\RoomModifiedEvent;
use OCA\Talk\Events\UserJoinedRoomEvent;
use OCP\AppFramework\App;
use OCP\AppFramework\Bootstrap\IBootstrap;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\AppFramework\Bootstrap\IRegistrationContext;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\Comments\ICommentsManager;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use OCP\IDBConnection;
use OCP\IUserManager;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;

// Imports added for optional apps and dependency injection overrides
use OCP\App\IAppManager;
use OCP\Talk\IBroker;
use OCP\IServerContainer;
use OCP\IURLGenerator;
use OCP\Files\IRootFolder;
use OCP\Share\IManager as IShareManager;
use OCP\L10N\IFactory as IL10NFactory;
use OCP\IUserSession;
use OCP\IGroupManager;
use OCA\ProjectCreatorAIO\Service\ProjectService;
use OCA\ProjectCreatorAIO\Service\ProjectActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectTalkIntegrationService;
use OCA\ProjectCreatorAIO\Service\FileTreeService;
use OCA\ProjectCreatorAIO\Db\ProjectNoteMapper;
use OCA\ProjectCreatorAIO\Db\ProjectMemberRoleMapper;
use OCA\ProjectCreatorAIO\Db\TimelineItemMapper;
use OCA\ProjectCreatorAIO\Db\ProjectActivityEventMapper;
use OCA\ProjectCreatorAIO\Db\ProjectDigestCursorMapper;
use OCA\Deck\Db\ChangeHelper;
use OCA\Deck\Db\CardMapper;
use OCA\Deck\Db\BoardMapper;
use OCA\Deck\Db\StackMapper;
use OCA\GroupFolders\Folder\FolderManager;
use OCA\GroupFolders\Mount\FolderStorageManager;
use OCA\Organization\Db\OrganizationMapper;
use OCA\Organization\Db\UserMapper as OrganizationUserMapper;
use OCA\Organization\Db\SubscriptionMapper;
use OCA\Organization\Db\PlanMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicySettingMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyMembershipMapper;
use OCA\ProjectCreatorAIO\Db\BoardPolicyDefaultRoleMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyMapper;
use OCA\ProjectCreatorAIO\Db\CardPolicyRoleMapper;
use OCA\ProjectCreatorAIO\Service\CardPolicyService;
use OCA\ProjectCreatorAIO\Controller\PolicyApiController;

class Application extends App implements IBootstrap {
    public const APP_ID = 'projectcreatoraio';
    public function __construct(array $urlParams = []) {
        parent::__construct(self::APP_ID, $urlParams);
    }
	
	public function register(IRegistrationContext $context): void {
		$context->registerDashboardWidget(ProjectsWidget::class);
		$context->registerNotifierService(Notifier::class);
		$context->registerEventListener(NodeWrittenEvent::class, WhiteboardWrittenListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileProcessingWrittenListener::class);

		// Only register Deck event listeners if Deck app is active
		if (class_exists(BoardCreatedEvent::class)) {
			$context->registerEventListener(BoardCreatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(BoardUpdatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(BoardDeletedEvent::class, DeckEventListener::class);
			$context->registerEventListener(CardCreatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(CardUpdatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(CardDeletedEvent::class, DeckEventListener::class);
			$context->registerEventListener(AclCreatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(AclUpdatedEvent::class, DeckEventListener::class);
			$context->registerEventListener(AclDeletedEvent::class, DeckEventListener::class);
		}

		// Files event listeners
		$context->registerEventListener(NodeCreatedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, FileEventListener::class);

		// Only register Talk event listeners if Talk app is active
		if (class_exists(ChatMessageSentEvent::class)) {
			$context->registerEventListener(ChatMessageSentEvent::class, TalkEventListener::class);
			$context->registerEventListener(AttendeesAddedEvent::class, TalkEventListener::class);
			$context->registerEventListener(AttendeeRemovedEvent::class, TalkEventListener::class);
			$context->registerEventListener(CallStartedEvent::class, TalkEventListener::class);
			$context->registerEventListener(CallEndedEvent::class, TalkEventListener::class);
			$context->registerEventListener(RoomModifiedEvent::class, TalkEventListener::class);
			$context->registerEventListener(ReactionAddedEvent::class, TalkEventListener::class);
			$context->registerEventListener(ReactionRemovedEvent::class, TalkEventListener::class);
			$context->registerEventListener(UserJoinedRoomEvent::class, TalkEventListener::class);
		}

        $context->registerService(ProjectMapper::class, function (ContainerInterface $c) {
            return new ProjectMapper(
                $c->get(IDBConnection::class),
                $c->get(PrivateFolderLinkMapper::class),
            );
        });

		$context->registerService(DeckDefaultCardsService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			if (!$appManager->isEnabledForAnyone('deck') || !class_exists(BoardService::class)) {
				return null;
			}
			return new DeckDefaultCardsService(
				$c->get(CardService::class),
				$c->get(LabelService::class),
				$c->get(StackService::class),
				$c->get(BoardService::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(ProjectTalkIntegrationService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$talkEnabled = $appManager->isEnabledForAnyone('spreed') && interface_exists(IBroker::class);
			return new ProjectTalkIntegrationService(
				$talkEnabled ? $c->get(IBroker::class) : null,
				$c->get(IServerContainer::class),
				$c->get(IUserManager::class),
				$c->get(IURLGenerator::class),
				$c->get(IRootFolder::class),
				$c->get(IShareManager::class),
				$c->get(ICommentsManager::class),
				$c->get(IL10NFactory::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(ProjectService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$deckEnabled = $appManager->isEnabledForAnyone('deck') && class_exists(BoardService::class);
			$groupfoldersEnabled = $appManager->isEnabledForAnyone('groupfolders') && class_exists(FolderManager::class);
			$organizationEnabled = $appManager->isEnabledForAnyone('organization') && class_exists(OrganizationMapper::class);

			return new ProjectService(
				$c->get(IUserSession::class),
				$c->get(IShareManager::class),
				$deckEnabled ? $c->get(BoardService::class) : null,
				$deckEnabled ? $c->get(DeckDefaultCardsService::class) : null,
				$c->get(IRootFolder::class),
				$c->get(ProjectMapper::class),
				$c->get(ProjectNoteMapper::class),
				$c->get(FileTreeService::class),
				$organizationEnabled ? $c->get(OrganizationMapper::class) : null,
				$organizationEnabled ? $c->get(OrganizationUserMapper::class) : null,
				$organizationEnabled ? $c->get(SubscriptionMapper::class) : null,
				$organizationEnabled ? $c->get(PlanMapper::class) : null,
				$c->get(IGroupManager::class),
				$groupfoldersEnabled ? $c->get(FolderManager::class) : null,
				$c->get(IDBConnection::class),
				$c->get(IUserManager::class),
				$groupfoldersEnabled ? $c->get(FolderStorageManager::class) : null,
				$deckEnabled ? $c->get(ChangeHelper::class) : null,
				$c->get(ProjectNotificationService::class),
				$c->get(ProjectActivityService::class),
				$c->get(ProjectDeckActivityService::class),
				$c->get(ProjectTalkIntegrationService::class),
				$deckEnabled ? $c->get(CardMapper::class) : null,
				$deckEnabled ? $c->get(StackService::class) : null,
				$deckEnabled ? $c->get(PermissionService::class) : null,
				$c->get(LoggerInterface::class),
				$c->get(ProjectMemberRoleMapper::class),
				$c->get(CardPolicyService::class),
			);
		});

		$context->registerService(TimelinePlanningService::class, function (ContainerInterface $c) {
			return new TimelinePlanningService(
				$c->get(IDBConnection::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(BoardPolicySettingMapper::class, function (ContainerInterface $c) {
			return new BoardPolicySettingMapper($c->get(IDBConnection::class));
		});
		$context->registerService(BoardPolicyRoleMapper::class, function (ContainerInterface $c) {
			return new BoardPolicyRoleMapper($c->get(IDBConnection::class));
		});
		$context->registerService(BoardPolicyMembershipMapper::class, function (ContainerInterface $c) {
			return new BoardPolicyMembershipMapper($c->get(IDBConnection::class));
		});
		$context->registerService(BoardPolicyDefaultRoleMapper::class, function (ContainerInterface $c) {
			return new BoardPolicyDefaultRoleMapper($c->get(IDBConnection::class));
		});
		$context->registerService(CardPolicyMapper::class, function (ContainerInterface $c) {
			return new CardPolicyMapper($c->get(IDBConnection::class));
		});
		$context->registerService(CardPolicyRoleMapper::class, function (ContainerInterface $c) {
			return new CardPolicyRoleMapper($c->get(IDBConnection::class));
		});

		$context->registerService(CardPolicyService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$deckEnabled = $appManager->isEnabledForAnyone('deck') && class_exists(CardMapper::class);
			$organizationEnabled = $appManager->isEnabledForAnyone('organization') && class_exists(OrganizationMapper::class);

			return new CardPolicyService(
				$c->get(BoardPolicySettingMapper::class),
				$c->get(BoardPolicyRoleMapper::class),
				$c->get(BoardPolicyMembershipMapper::class),
				$c->get(BoardPolicyDefaultRoleMapper::class),
				$c->get(CardPolicyMapper::class),
				$c->get(CardPolicyRoleMapper::class),
				$c->get(ProjectMapper::class),
				$c->get(IGroupManager::class),
				$c->get(IUserManager::class),
				$deckEnabled ? $c->get(CardMapper::class) : null,
				$deckEnabled ? $c->get(StackMapper::class) : null,
				$organizationEnabled ? $c->get(OrganizationUserMapper::class) : null
			);
		});

		$context->registerService(PolicyApiController::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$deckEnabled = $appManager->isEnabledForAnyone('deck') && class_exists(CardMapper::class);
			return new PolicyApiController(
				self::APP_ID,
				$c->get(\OCP\IRequest::class),
				$c->get(BoardPolicySettingMapper::class),
				$c->get(BoardPolicyRoleMapper::class),
				$c->get(BoardPolicyMembershipMapper::class),
				$c->get(BoardPolicyDefaultRoleMapper::class),
				$c->get(CardPolicyMapper::class),
				$c->get(CardPolicyRoleMapper::class),
				$c->get(CardPolicyService::class),
				$c->get(IUserSession::class),
				$c->get(IDBConnection::class),
				$deckEnabled ? $c->get(CardMapper::class) : null
			);
		});

		$context->registerService(DetectStaleProjectsJob::class, function (ContainerInterface $c) {
			return new DetectStaleProjectsJob(
				$c->get(ITimeFactory::class),
				$c->get(ProjectDeckActivityService::class),
			);
		});

		$context->registerService(SendProjectDigestJob::class, function (ContainerInterface $c) {
			return new SendProjectDigestJob(
				$c->get(ITimeFactory::class),
				$c->get(ProjectDigestService::class),
			);
		});

		$context->registerService(ProjectRetentionService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$deckEnabled = $appManager->isEnabledForAnyone('deck') && class_exists(BoardMapper::class);
			$groupfoldersEnabled = $appManager->isEnabledForAnyone('groupfolders') && class_exists(FolderManager::class);

			return new ProjectRetentionService(
				$c->get(ProjectMapper::class),
				$c->get(ProjectNoteMapper::class),
				$c->get(TimelineItemMapper::class),
				$c->get(PrivateFolderLinkMapper::class),
				$c->get(ProjectActivityEventMapper::class),
				$c->get(ProjectDigestCursorMapper::class),
				$c->get(ProjectMemberRoleMapper::class),
				$deckEnabled ? $c->get(BoardMapper::class) : null,
				$groupfoldersEnabled ? $c->get(FolderManager::class) : null,
				$groupfoldersEnabled ? $c->get(FolderStorageManager::class) : null,
				$c->get(IRootFolder::class),
				$c->get(IGroupManager::class),
				$c->get(IDBConnection::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(PurgeArchivedProjectsJob::class, function (ContainerInterface $c) {
			return new PurgeArchivedProjectsJob(
				$c->get(ITimeFactory::class),
				$c->get(ProjectRetentionService::class),
			);
		});

		$context->registerService(ProcessPendingFileProcessingJob::class, function (ContainerInterface $c) {
			return new ProcessPendingFileProcessingJob(
				$c->get(ITimeFactory::class),
				$c->get(FileProcessingPipelineService::class),
			);
		});

		$context->registerService(ProjectDownloadService::class, function (ContainerInterface $c) {
			$appManager = $c->get(IAppManager::class);
			$deckEnabled = $appManager->isEnabledForAnyone('deck') && class_exists(StackMapper::class);

			return new ProjectDownloadService(
				$c->get(ProjectNoteMapper::class),
				$c->get(TimelineItemMapper::class),
				$c->get(ProjectActivityEventMapper::class),
				$deckEnabled ? $c->get(StackMapper::class) : null,
				$deckEnabled ? $c->get(CardMapper::class) : null,
				$c->get(IRootFolder::class),
				$c->get(IUserManager::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(GenerateProjectExportJob::class, function (ContainerInterface $c) {
			return new GenerateProjectExportJob(
				$c->get(ITimeFactory::class),
				$c->get(ProjectMapper::class),
				$c->get(ProjectDownloadService::class),
				$c->get(ProjectNotificationService::class),
				$c->get(IUserManager::class),
				$c->get(LoggerInterface::class),
			);
		});

		$context->registerService(PurgeOldExportsJob::class, function (ContainerInterface $c) {
			return new PurgeOldExportsJob(
				$c->get(ITimeFactory::class),
				$c->get(ProjectDownloadService::class),
				$c->get(IUserManager::class),
				$c->get(LoggerInterface::class),
			);
		});

	}

	public function boot(IBootContext $context): void {
		$context->injectFn(function (IJobList $jobList): void {
			if (!$jobList->has(DetectStaleProjectsJob::class, null)) {
				$jobList->add(DetectStaleProjectsJob::class);
			}
			if (!$jobList->has(SendProjectDigestJob::class, null)) {
				$jobList->add(SendProjectDigestJob::class);
			}
			if (!$jobList->has(PurgeArchivedProjectsJob::class, null)) {
				$jobList->add(PurgeArchivedProjectsJob::class);
			}
			if (!$jobList->has(ProcessPendingFileProcessingJob::class, null)) {
				$jobList->add(ProcessPendingFileProcessingJob::class);
			}
			if (!$jobList->has(PurgeOldExportsJob::class, null)) {
				$jobList->add(PurgeOldExportsJob::class);
			}
		});
	}
}
