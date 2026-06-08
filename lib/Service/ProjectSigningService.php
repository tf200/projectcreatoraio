<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCA\ProjectCreatorAIO\Db\Project;
use OCA\ProjectCreatorAIO\Db\ProjectSigningRequest;
use OCA\ProjectCreatorAIO\Db\ProjectSigningRequestMapper;
use OCP\App\IAppManager;
use OCP\AppFramework\OCS\OCSException;
use OCP\Files\IRootFolder;
use OCP\Files\File;
use OCP\Http\Client\IClientService;
use OCP\IRequest;
use OCP\IURLGenerator;
use Psr\Log\LoggerInterface;

class ProjectSigningService {
	public function __construct(
		private readonly ProjectSigningRequestMapper $mapper,
		private readonly IRootFolder $rootFolder,
		private readonly IAppManager $appManager,
		private readonly IClientService $clientService,
		private readonly IURLGenerator $urlGenerator,
		private readonly IRequest $request,
		private readonly ProjectActivityService $activityService,
		private readonly LoggerInterface $logger,
	) {
	}

	/** @return ProjectSigningRequest[] */
	public function listForProject(int $projectId): array {
		return $this->mapper->findByProject($projectId);
	}

	public function getLatestForFile(int $projectId, int $fileId): ?ProjectSigningRequest {
		return $this->mapper->findLatestByProjectAndFile($projectId, $fileId);
	}

	public function createRequest(Project $project, int $fileId, string $flow, array $signers, string $createdBy): ProjectSigningRequest {
		if (!$this->appManager->isEnabledForUser('libresign')) {
			throw new OCSException('LibreSign is not enabled.', 503);
		}

		$flow = $flow === 'ordered_numeric' ? 'ordered_numeric' : 'parallel';
		$signers = $this->normalizeSigners($signers);
		if ($signers === []) {
			throw new OCSException('At least one signer email is required.', 400);
		}

		$file = $this->requireFile($createdBy, $fileId);
		if (strtolower((string) $file->getMimeType()) !== 'application/pdf') {
			throw new OCSException('Only PDF files can be sent for signature.', 400);
		}

		$record = $this->mapper->createRecord(
			(int) $project->getId(),
			$fileId,
			$file->getPath(),
			$file->getName(),
			$flow,
			$signers,
			$createdBy,
		);

		try {
			$response = $this->requestLibreSignSignature($file, $flow, $signers);
			$record->setLibresignFileId($this->extractLibreSignId($response));
			$record->setStatus('pending');
			$record = $this->mapper->saveRecord($record);
			$this->activityService->recordWithActorInfo($project, 'signing_requested', ProjectActivityService::SOURCE_FILES, $createdBy, $createdBy, [
				'fileId' => $fileId,
				'fileName' => $file->getName(),
				'signerCount' => count($signers),
			]);
			return $record;
		} catch (\Throwable $e) {
			$record->setStatus('failed');
			$record->setLastError($e->getMessage());
			$this->mapper->saveRecord($record);
			$this->logger->error('Failed to create LibreSign request', ['exception' => $e, 'projectId' => $project->getId(), 'fileId' => $fileId]);
			throw new OCSException('Could not create LibreSign request: ' . $e->getMessage(), 502);
		}
	}

	private function requireFile(string $userId, int $fileId): File {
		$userFolder = $this->rootFolder->getUserFolder($userId);
		$nodes = $userFolder->getById($fileId);
		$node = $nodes[0] ?? null;
		if (!$node instanceof File) {
			throw new OCSException('File not found.', 404);
		}
		return $node;
	}

	private function normalizeSigners(array $signers): array {
		$normalized = [];
		$seen = [];
		foreach ($signers as $signer) {
			if (!is_array($signer)) {
				continue;
			}

			$userId = trim((string) ($signer['userId'] ?? ''));
			$email = strtolower(trim((string) ($signer['email'] ?? '')));
			$displayName = trim((string) ($signer['displayName'] ?? $signer['name'] ?? ''));
			if ($userId !== '') {
				$key = 'account:' . $userId;
				if (isset($seen[$key])) {
					continue;
				}
				$seen[$key] = true;
				$normalized[] = [
					'displayName' => $displayName !== '' ? $displayName : $userId,
					'identifyMethods' => [[
						'method' => 'account',
						'value' => $userId,
					]],
				];
				continue;
			}

			if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
				continue;
			}
			$key = 'email:' . $email;
			if (isset($seen[$key])) {
				continue;
			}
			$seen[$key] = true;
			$normalized[] = [
				'displayName' => $displayName !== '' ? $displayName : $email,
				'identifyMethods' => [[
					'method' => 'email',
					'value' => $email,
				]],
			];
		}
		return $normalized;
	}

	private function requestLibreSignSignature(File $file, string $flow, array $signers): array {
		$client = $this->clientService->newClient();
		$url = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/libresign/api/v1/request-signature');
		$options = [
			'headers' => [
				'OCS-APIRequest' => 'true',
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'body' => json_encode([
				'name' => $file->getName(),
				'nodeId' => $file->getId(),
				'signatureFlow' => $flow,
				'signers' => $signers,
			], JSON_THROW_ON_ERROR),
		];

		$cookie = $this->request->getHeader('Cookie');
		if ($cookie !== '') {
			$options['headers']['Cookie'] = $cookie;
		}
		$requestToken = $this->request->getHeader('requesttoken');
		if ($requestToken !== '') {
			$options['headers']['requesttoken'] = $requestToken;
		}

		$response = $client->post($url, $options);
		$statusCode = $response->getStatusCode();
		$body = (string) $response->getBody();
		$decoded = json_decode($body, true);
		if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
			throw new \RuntimeException('LibreSign returned HTTP ' . $statusCode);
		}
		return $decoded;
	}

	private function extractLibreSignId(array $response): ?string {
		$candidates = [
			$response['ocs']['data']['file']['id'] ?? null,
			$response['ocs']['data']['id'] ?? null,
			$response['id'] ?? null,
		];
		foreach ($candidates as $candidate) {
			if ($candidate !== null && trim((string) $candidate) !== '') {
				return (string) $candidate;
			}
		}
		return null;
	}
}
