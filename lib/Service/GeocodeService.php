<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use Psr\Log\LoggerInterface;

/**
 * Geocodes a project's structured address via Nominatim, caching positive AND
 * negative results in pca_geocode_cache so the same address is never sent to
 * Nominatim twice (per the OSMF usage policy).
 */
class GeocodeService {

	private const NOMINATIM_URL = 'https://nominatim.openstreetmap.org/search';
	private const HTTP_TIMEOUT_SECONDS = 10;

	public function __construct(
		private IDBConnection $db,
		private IClientService $clientService,
		private IAppManager $appManager,
		private IConfig $config,
		private LoggerInterface $logger,
	) {
	}

	/**
	 * @return array{status:string, lat?:float, lng?:float, displayName?:?string, source?:string, addrHash?:string, fromCache?:bool}
	 */
	public function geocodeProject(int $projectId): array {
		$addr = $this->fetchProjectAddress($projectId);
		if ($addr === null) {
			return ['status' => 'no_address'];
		}

		$street = trim((string)($addr['street'] ?? ''));
		$city = trim((string)($addr['city'] ?? ''));
		$zip = trim((string)($addr['zip'] ?? ''));

		if ($street === '' && $city === '' && $zip === '') {
			return ['status' => 'no_address'];
		}

		$addrHash = $this->hashAddress($street, $city, $zip);

		$cached = $this->lookupCache($addrHash);
		if ($cached !== null) {
			if ($cached['lat'] === null || $cached['lng'] === null) {
				return ['status' => 'not_found', 'addrHash' => $addrHash, 'fromCache' => true];
			}
			return [
				'status' => 'ok',
				'lat' => (float)$cached['lat'],
				'lng' => (float)$cached['lng'],
				'displayName' => $cached['display_name'],
				'source' => $cached['source'],
				'addrHash' => $addrHash,
				'fromCache' => true,
			];
		}

		$hit = $this->callNominatim($this->buildQueryString($street, $city, $zip));

		if ($hit === null) {
			// Transient failure — do not cache; retry next time.
			return ['status' => 'unavailable'];
		}

		if ($hit === []) {
			$this->insertCache($addrHash, null, null, null, 'nominatim');
			return ['status' => 'not_found', 'addrHash' => $addrHash, 'fromCache' => false];
		}

		$lat = (float)$hit['lat'];
		$lng = (float)$hit['lng'];
		$displayName = $hit['display_name'] ?? null;
		$this->insertCache($addrHash, $lat, $lng, $displayName, 'nominatim');

		return [
			'status' => 'ok',
			'lat' => $lat,
			'lng' => $lng,
			'displayName' => $displayName,
			'source' => 'nominatim',
			'addrHash' => $addrHash,
			'fromCache' => false,
		];
	}

	/**
	 * @return array{street:string,city:string,zip:string}|null
	 */
	protected function fetchProjectAddress(int $projectId): ?array {
		$stmt = $this->db->prepare(
			'SELECT loc_street, loc_city, loc_zip
			 FROM *PREFIX*custom_projects WHERE id = ? LIMIT 1'
		);
		$stmt->bindValue(1, $projectId, \PDO::PARAM_INT);
		$stmt->execute();
		$row = $stmt->fetch();
		if (!$row) {
			return null;
		}
		return [
			'street' => (string)($row['loc_street'] ?? ''),
			'city' => (string)($row['loc_city'] ?? ''),
			'zip' => (string)($row['loc_zip'] ?? ''),
		];
	}

	/**
	 * @return array{lat:?string,lng:?string,display_name:?string,source:string}|null
	 */
	protected function lookupCache(string $addrHash): ?array {
		$stmt = $this->db->prepare(
			'SELECT lat, lng, display_name, source
			 FROM *PREFIX*pca_geocode_cache WHERE addr_hash = ? LIMIT 1'
		);
		$stmt->bindValue(1, $addrHash, \PDO::PARAM_STR);
		$stmt->execute();
		$row = $stmt->fetch();
		return $row ?: null;
	}

	protected function insertCache(string $addrHash, ?float $lat, ?float $lng, ?string $displayName, string $source): void {
		$stmt = $this->db->prepare(
			'INSERT INTO *PREFIX*pca_geocode_cache
			 (addr_hash, lat, lng, display_name, source, created_at)
			 VALUES (?, ?, ?, ?, ?, ?)'
		);
		$stmt->bindValue(1, $addrHash, \PDO::PARAM_STR);
		if ($lat === null) {
			$stmt->bindValue(2, null, \PDO::PARAM_NULL);
		} else {
			$stmt->bindValue(2, number_format($lat, 7, '.', ''), \PDO::PARAM_STR);
		}
		if ($lng === null) {
			$stmt->bindValue(3, null, \PDO::PARAM_NULL);
		} else {
			$stmt->bindValue(3, number_format($lng, 7, '.', ''), \PDO::PARAM_STR);
		}
		if ($displayName === null) {
			$stmt->bindValue(4, null, \PDO::PARAM_NULL);
		} else {
			$stmt->bindValue(4, mb_substr($displayName, 0, 255), \PDO::PARAM_STR);
		}
		$stmt->bindValue(5, $source, \PDO::PARAM_STR);
		$stmt->bindValue(6, time(), \PDO::PARAM_INT);
		$stmt->execute();
	}

	private function buildQueryString(string $street, string $city, string $zip): string {
		$parts = array_filter([$street, $zip, $city], static fn ($p) => $p !== '');
		return implode(', ', $parts);
	}

	private function hashAddress(string $street, string $city, string $zip): string {
		$normalized = strtolower(trim($street))
			. '|' . strtolower(trim($city))
			. '|' . strtolower(trim($zip));
		return hash('sha256', $normalized);
	}

	/**
	 * @return array{lat:string,lng:string,display_name:?string}|array{}|null
	 *                                                                        associative hit; [] = no match; null = transient failure.
	 */
	protected function callNominatim(string $query) {
		$userAgent = sprintf(
			'Nextcloud-ProjectCreatorAIO/%s (%s)',
			$this->appManager->getAppVersion('projectcreatoraio'),
			$this->resolveInstanceHost()
		);
		try {
			$client = $this->clientService->newClient();
			$response = $client->get(self::NOMINATIM_URL, [
				'query' => ['format' => 'jsonv2', 'limit' => 1, 'q' => $query],
				'headers' => ['User-Agent' => $userAgent, 'Accept' => 'application/json'],
				'timeout' => self::HTTP_TIMEOUT_SECONDS,
			]);
		} catch (\Throwable $e) {
			$this->logger->warning('Nominatim request failed', ['app' => 'projectcreatoraio', 'exception' => $e]);
			return null;
		}

		if ($response->getStatusCode() !== 200) {
			$this->logger->warning('Nominatim non-200', ['app' => 'projectcreatoraio', 'status' => $response->getStatusCode()]);
			return null;
		}

		$decoded = json_decode((string)$response->getBody(), true);
		if (!is_array($decoded)) {
			return null;
		}
		if (empty($decoded)) {
			return [];
		}
		$first = $decoded[0];
		if (!isset($first['lat'], $first['lon'])) {
			return [];
		}
		return [
			'lat' => $first['lat'],
			'lng' => $first['lon'],
			'display_name' => $first['display_name'] ?? null,
		];
	}

	private function resolveInstanceHost(): string {
		$cli = (string)$this->config->getSystemValue('overwrite.cli.url', '');
		if ($cli !== '') {
			$host = parse_url($cli, PHP_URL_HOST);
			if (is_string($host) && $host !== '') {
				return $host;
			}
		}
		$trusted = $this->config->getSystemValue('trusted_domains', []);
		if (is_array($trusted) && isset($trusted[0]) && is_string($trusted[0]) && $trusted[0] !== '') {
			return $trusted[0];
		}
		return 'unknown-host';
	}
}
