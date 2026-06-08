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

	public function createRequest(Project $project, int $fileId, string $flow, array $signers, string $createdBy, array $placements = []): ProjectSigningRequest {
		if (!$this->appManager->isEnabledForUser('libresign')) {
			throw new OCSException('LibreSign is not enabled.', 503);
		}

		$flow = $flow === 'ordered_numeric' ? 'ordered_numeric' : 'parallel';
		$signers = $this->normalizeSigners($signers);
		if ($signers === []) {
			throw new OCSException('At least one signer is required.', 400);
		}
		$placements = $this->normalizePlacements($placements);

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
			$this->createLibreSignPlacements($response, $signers, $placements);
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
				'file' => [
					'nodeId' => $file->getId(),
				],
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

		try {
			$response = $client->post($url, $options);
		} catch (\Throwable $e) {
			throw new \RuntimeException('LibreSign rejected request: ' . $this->extractHttpErrorMessage($e), 0, $e);
		}
		$statusCode = $response->getStatusCode();
		$body = (string) $response->getBody();
		$decoded = json_decode($body, true);
		if ($statusCode < 200 || $statusCode >= 300 || !is_array($decoded)) {
			throw new \RuntimeException('LibreSign returned HTTP ' . $statusCode . ': ' . $this->extractLibreSignMessage($decoded));
		}
		return $decoded;
	}

	private function normalizePlacements(array $placements): array {
		$normalized = [];
		foreach ($placements as $placement) {
			if (!is_array($placement)) {
				continue;
			}
			$signerKey = trim((string) ($placement['signerKey'] ?? ''));
			if ($signerKey === '') {
				continue;
			}
			$page = max(1, (int) ($placement['page'] ?? 1));
			$left = max(0, (int) ($placement['left'] ?? 80));
			$top = max(0, (int) ($placement['top'] ?? 120));
			$width = max(1, (int) ($placement['width'] ?? 180));
			$height = max(1, (int) ($placement['height'] ?? 60));
			$type = in_array(($placement['type'] ?? 'signature'), ['signature', 'initial', 'date', 'datetime', 'text'], true)
				? (string) $placement['type']
				: 'signature';
			$normalized[$signerKey] = compact('signerKey', 'page', 'left', 'top', 'width', 'height', 'type');
		}
		return $normalized;
	}

	private function createLibreSignPlacements(array $response, array $requestedSigners, array $placements): void {
		if ($placements === []) {
			return;
		}
		$uuid = $this->extractLibreSignUuid($response);
		$fileId = $this->extractLibreSignId($response);
		$signers = $response['ocs']['data']['signers'] ?? $response['signers'] ?? [];
		if ($uuid === null || $fileId === null || !is_array($signers)) {
			throw new \RuntimeException('LibreSign did not return placement identifiers.');
		}

		foreach ($signers as $index => $signer) {
			if (!is_array($signer)) {
				continue;
			}
			$key = $this->signerKeyFromResponse($signer) ?? $this->signerKeyFromRequested($requestedSigners[$index] ?? []);
			$placement = $key !== null ? ($placements[$key] ?? null) : null;
			$signRequestId = (int) ($signer['signRequestId'] ?? 0);
			if ($placement === null || $signRequestId <= 0) {
				continue;
			}
			$this->requestLibreSignFileElement($uuid, (int) $fileId, $signRequestId, $placement);
		}
	}

	private function requestLibreSignFileElement(string $uuid, int $fileId, int $signRequestId, array $placement): void {
		$client = $this->clientService->newClient();
		$url = $this->urlGenerator->getAbsoluteURL('/ocs/v2.php/apps/libresign/api/v1/file-element/' . rawurlencode($uuid));
		$options = [
			'headers' => [
				'OCS-APIRequest' => 'true',
				'Content-Type' => 'application/json',
				'Accept' => 'application/json',
			],
			'body' => json_encode([
				'signRequestId' => $signRequestId,
				'fileId' => $fileId,
				'type' => $placement['type'],
				'coordinates' => [
					'page' => $placement['page'],
					'left' => $placement['left'],
					'top' => $placement['top'],
					'width' => $placement['width'],
					'height' => $placement['height'],
				],
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
		try {
			$response = $client->post($url, $options);
		} catch (\Throwable $e) {
			throw new \RuntimeException('LibreSign rejected placement: ' . $this->extractHttpErrorMessage($e), 0, $e);
		}
		if ($response->getStatusCode() < 200 || $response->getStatusCode() >= 300) {
			throw new \RuntimeException('LibreSign placement returned HTTP ' . $response->getStatusCode());
		}
	}

	private function signerKeyFromResponse(array $signer): ?string {
		foreach (($signer['identifyMethods'] ?? []) as $method) {
			if (!is_array($method)) {
				continue;
			}
			$name = trim((string) ($method['method'] ?? ''));
			$value = trim((string) ($method['value'] ?? ''));
			if ($name !== '' && $value !== '') {
				return $name . ':' . strtolower($value);
			}
		}
		if (!empty($signer['uid'])) {
			return 'account:' . strtolower((string) $signer['uid']);
		}
		if (!empty($signer['email'])) {
			return 'email:' . strtolower((string) $signer['email']);
		}
		return null;
	}

	private function signerKeyFromRequested(array $signer): ?string {
		foreach (($signer['identifyMethods'] ?? []) as $method) {
			if (!is_array($method)) {
				continue;
			}
			$name = trim((string) ($method['method'] ?? ''));
			$value = trim((string) ($method['value'] ?? ''));
			if ($name !== '' && $value !== '') {
				return $name . ':' . strtolower($value);
			}
		}
		return null;
	}

	private function extractLibreSignUuid(array $response): ?string {
		$candidates = [
			$response['ocs']['data']['uuid'] ?? null,
			$response['ocs']['data']['file']['uuid'] ?? null,
			$response['uuid'] ?? null,
		];
		foreach ($candidates as $candidate) {
			if ($candidate !== null && trim((string) $candidate) !== '') {
				return (string) $candidate;
			}
		}
		return null;
	}

	private function extractHttpErrorMessage(\Throwable $e): string {
		if (method_exists($e, 'getResponse')) {
			$response = $e->getResponse();
			if ($response !== null && method_exists($response, 'getBody')) {
				$decoded = json_decode((string) $response->getBody(), true);
				return $this->extractLibreSignMessage(is_array($decoded) ? $decoded : null);
			}
		}
		return $e->getMessage();
	}

	private function extractLibreSignMessage(?array $decoded): string {
		$candidates = [
			$decoded['ocs']['data']['message'] ?? null,
			$decoded['ocs']['data']['errors'][0]['message'] ?? null,
			$decoded['ocs']['meta']['message'] ?? null,
			$decoded['message'] ?? null,
		];
		foreach ($candidates as $candidate) {
			if ($candidate !== null && trim((string) $candidate) !== '') {
				return (string) $candidate;
			}
		}
		return 'Unknown LibreSign error';
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
