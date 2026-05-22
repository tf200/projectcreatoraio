<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\BackgroundJob;

use OCA\ProjectCreatorAIO\Db\ProjectMapper;
use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
use OCA\ProjectCreatorAIO\Service\ProjectNotificationService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\QueuedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class GenerateProjectExportJob extends QueuedJob
{
	public function __construct(
		ITimeFactory $time,
		private readonly ProjectMapper $projectMapper,
		private readonly ProjectDownloadService $downloadService,
		private readonly ProjectNotificationService $notificationService,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setTimeSensitive(self::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$projectId = (int) ($argument['projectId'] ?? 0);
		$userId = (string) ($argument['userId'] ?? '');

		if ($projectId <= 0 || $userId === '') {
			$this->logger->warning('GenerateProjectExportJob: invalid arguments', [
				'argument' => $argument,
			]);
			return;
		}

		$project = $this->projectMapper->find($projectId);
		if ($project === null) {
			$this->logger->warning('GenerateProjectExportJob: project not found', [
				'projectId' => $projectId,
			]);
			return;
		}

		$user = $this->userManager->get($userId);
		if ($user === null) {
			$this->logger->warning('GenerateProjectExportJob: user not found', [
				'userId' => $userId,
			]);
			return;
		}

		$zipPath = $this->downloadService->generateZip($project, $user);

		if ($zipPath === null) {
			$this->logger->error('GenerateProjectExportJob: ZIP generation failed', [
				'projectId' => $projectId,
				'userId' => $userId,
			]);
			return;
		}

		$this->notificationService->notifyExportReady($project, $user);

		$this->logger->info('GenerateProjectExportJob: export completed', [
			'projectId' => $projectId,
			'userId' => $userId,
			'zipPath' => $zipPath,
		]);
	}
}
