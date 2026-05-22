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
use OCA\Deck\Db\StackMapper;
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
use OCA\Deck\Service\CardPolicyService;
use OCA\Deck\Service\LabelService;
use OCA\Deck\Service\StackService;
use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Listener\DeckEventListener;
use OCA\ProjectCreatorAIO\Listener\FileEventListener;
use OCA\ProjectCreatorAIO\Listener\FileProcessingWrittenListener;
use OCA\ProjectCreatorAIO\Listener\TalkEventListener;
use OCA\ProjectCreatorAIO\Listener\WhiteboardWrittenListener;
use OCA\ProjectCreatorAIO\Notification\Notifier;
use OCA\ProjectCreatorAIO\Service\DeckDefaultCardsService;
use OCA\ProjectCreatorAIO\Service\ProjectDeckActivityService;
use OCA\ProjectCreatorAIO\Service\ProjectDigestService;
use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
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
use OCP\AppFramework\IAppContainer;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJobList;
use OCP\Files\Events\Node\NodeCopiedEvent;
use OCP\Files\Events\Node\NodeCreatedEvent;
use OCP\Files\Events\Node\NodeDeletedEvent;
use OCP\Files\Events\Node\NodeRenamedEvent;
use OCP\Files\Events\Node\NodeWrittenEvent;
use Psr\Log\LoggerInterface;

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

		// Deck event listeners
		$context->registerEventListener(BoardCreatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(BoardUpdatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(BoardDeletedEvent::class, DeckEventListener::class);
		$context->registerEventListener(CardCreatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(CardUpdatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(CardDeletedEvent::class, DeckEventListener::class);
		$context->registerEventListener(AclCreatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(AclUpdatedEvent::class, DeckEventListener::class);
		$context->registerEventListener(AclDeletedEvent::class, DeckEventListener::class);

		// Files event listeners
		$context->registerEventListener(NodeCreatedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeWrittenEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeDeletedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeRenamedEvent::class, FileEventListener::class);
		$context->registerEventListener(NodeCopiedEvent::class, FileEventListener::class);

		// Talk event listeners
		$context->registerEventListener(ChatMessageSentEvent::class, TalkEventListener::class);
		$context->registerEventListener(AttendeesAddedEvent::class, TalkEventListener::class);
		$context->registerEventListener(AttendeeRemovedEvent::class, TalkEventListener::class);
		$context->registerEventListener(CallStartedEvent::class, TalkEventListener::class);
		$context->registerEventListener(CallEndedEvent::class, TalkEventListener::class);
		$context->registerEventListener(RoomModifiedEvent::class, TalkEventListener::class);
		$context->registerEventListener(ReactionAddedEvent::class, TalkEventListener::class);
		$context->registerEventListener(ReactionRemovedEvent::class, TalkEventListener::class);
		$context->registerEventListener(UserJoinedRoomEvent::class, TalkEventListener::class);

        $context->registerService('ProjectMapper', function (IAppContainer $c) {
            return new ProjectMapper(
                $c->getServer()->getDatabaseConnection(),
                $c->query(PrivateFolderLinkMapper::class),
            );
        });

		$context->registerService(DeckDefaultCardsService::class, function (IAppContainer $c) {
			return new DeckDefaultCardsService(
				$c->getServer()->query(CardService::class),
				$c->getServer()->query(CardPolicyService::class),
				$c->getServer()->query(LabelService::class),
				$c->getServer()->query(StackService::class),
				$c->getServer()->query(BoardService::class),
				$c->getServer()->get(LoggerInterface::class),
			);
		});

		$context->registerService(TimelinePlanningService::class, function (IAppContainer $c) {
			return new TimelinePlanningService(
				$c->getServer()->getDatabaseConnection(),
				$c->getServer()->get(LoggerInterface::class),
			);
		});

		$context->registerService(DetectStaleProjectsJob::class, function (IAppContainer $c) {
			return new DetectStaleProjectsJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(ProjectDeckActivityService::class),
			);
		});

		$context->registerService(SendProjectDigestJob::class, function (IAppContainer $c) {
			return new SendProjectDigestJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(ProjectDigestService::class),
			);
		});

		$context->registerService(PurgeArchivedProjectsJob::class, function (IAppContainer $c) {
			return new PurgeArchivedProjectsJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(ProjectRetentionService::class),
			);
		});

		$context->registerService(ProcessPendingFileProcessingJob::class, function (IAppContainer $c) {
			return new ProcessPendingFileProcessingJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(\OCA\ProjectCreatorAIO\Service\FileProcessingPipelineService::class),
			);
		});

		$context->registerService(GenerateProjectExportJob::class, function (IAppContainer $c) {
			return new GenerateProjectExportJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(\OCA\ProjectCreatorAIO\Db\ProjectMapper::class),
				$c->getServer()->query(ProjectDownloadService::class),
				$c->getServer()->query(\OCA\ProjectCreatorAIO\Service\ProjectNotificationService::class),
				$c->getServer()->query(\OCP\IUserManager::class),
				$c->getServer()->get(LoggerInterface::class),
			);
		});

		$context->registerService(PurgeOldExportsJob::class, function (IAppContainer $c) {
			return new PurgeOldExportsJob(
				$c->getServer()->get(ITimeFactory::class),
				$c->getServer()->query(ProjectDownloadService::class),
				$c->getServer()->query(\OCP\IUserManager::class),
				$c->getServer()->get(LoggerInterface::class),
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
