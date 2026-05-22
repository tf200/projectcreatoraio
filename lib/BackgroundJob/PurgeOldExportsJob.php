<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\BackgroundJob;

use OCA\ProjectCreatorAIO\Service\ProjectDownloadService;
use OCP\AppFramework\Utility\ITimeFactory;
use OCP\BackgroundJob\IJob;
use OCP\BackgroundJob\TimedJob;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;

class PurgeOldExportsJob extends TimedJob
{
	private const MAX_AGE_SECONDS = 86400; // 24 hours

	public function __construct(
		ITimeFactory $time,
		private readonly ProjectDownloadService $downloadService,
		private readonly IUserManager $userManager,
		private readonly LoggerInterface $logger,
	) {
		parent::__construct($time);
		$this->setInterval(6 * 60 * 60); // Every 6 hours
		$this->setTimeSensitivity(IJob::TIME_INSENSITIVE);
	}

	protected function run($argument): void
	{
		$totalDeleted = 0;

		$this->userManager->callForAllUsers(function ($user) use (&$totalDeleted) {
			try {
				$deleted = $this->downloadService->purgeOldExports($user, self::MAX_AGE_SECONDS);
				$totalDeleted += $deleted;
			} catch (\Throwable $e) {
				$this->logger->warning('PurgeOldExportsJob: failed for user', [
					'userId' => $user->getUID(),
					'exception' => $e,
				]);
			}
		});

		if ($totalDeleted > 0) {
			$this->logger->info('PurgeOldExportsJob: purged old exports', [
				'deleted' => $totalDeleted,
			]);
		}
	}
}
