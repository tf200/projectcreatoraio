<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Service\GeocodeService;
use OCP\App\IAppManager;
use OCP\Http\Client\IClientService;
use OCP\IConfig;
use OCP\IDBConnection;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class GeocodeServiceTest extends TestCase {
	/**
	 * @return GeocodeService&MockObject
	 */
	private function service(): GeocodeService {
		return $this->getMockBuilder(GeocodeService::class)
			->setConstructorArgs([
				$this->createMock(IDBConnection::class),
				$this->createMock(IClientService::class),
				$this->createMock(IAppManager::class),
				$this->createMock(IConfig::class),
				$this->createMock(LoggerInterface::class),
			])
			->onlyMethods(['fetchProjectAddress', 'lookupCache', 'insertCache', 'callNominatim'])
			->getMock();
	}

	public function testReturnsNoAddressWhenProjectMissing(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(null);
		$svc->expects($this->never())->method('callNominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('no_address', $result['status']);
	}

	public function testReturnsNoAddressWhenAllFieldsEmpty(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => ' ', 'city' => '', 'zip' => '']);
		$svc->expects($this->never())->method('callNominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('no_address', $result['status']);
	}

	public function testReturnsCachedPositiveResult(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => '1 Rue', 'city' => 'Paris', 'zip' => '75001']);
		$svc->method('lookupCache')->willReturn([
			'lat' => '48.8566000', 'lng' => '2.3522000',
			'display_name' => 'Paris, France', 'source' => 'nominatim',
		]);
		$svc->expects($this->never())->method('callNominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('ok', $result['status']);
		$this->assertTrue($result['fromCache']);
		$this->assertEqualsWithDelta(48.8566, $result['lat'], 0.0001);
		$this->assertEqualsWithDelta(2.3522, $result['lng'], 0.0001);
	}

	public function testReturnsCachedNegativeResult(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => '1 Rue', 'city' => 'Paris', 'zip' => '75001']);
		$svc->method('lookupCache')->willReturn([
			'lat' => null, 'lng' => null, 'display_name' => null, 'source' => 'nominatim',
		]);
		$svc->expects($this->never())->method('callNominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('not_found', $result['status']);
		$this->assertTrue($result['fromCache']);
	}

	public function testGeocodesAndCachesOnCacheMiss(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => '1 Rue', 'city' => 'Paris', 'zip' => '75001']);
		$svc->method('lookupCache')->willReturn(null);
		$svc->method('callNominatim')->willReturn([
			'lat' => '48.8566', 'lng' => '2.3522', 'display_name' => 'Paris, France',
		]);
		$svc->expects($this->once())->method('insertCache')
			->with($this->anything(), $this->equalTo(48.8566), $this->equalTo(2.3522), $this->anything(), 'nominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('ok', $result['status']);
		$this->assertFalse($result['fromCache']);
	}

	public function testCachesNegativeWhenNominatimHasNoMatch(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => 'Nowhere', 'city' => '', 'zip' => '']);
		$svc->method('lookupCache')->willReturn(null);
		$svc->method('callNominatim')->willReturn([]);
		$svc->expects($this->once())->method('insertCache')
			->with($this->anything(), $this->isNull(), $this->isNull(), $this->isNull(), 'nominatim');

		$result = $svc->geocodeProject(1);
		$this->assertSame('not_found', $result['status']);
		$this->assertFalse($result['fromCache']);
	}

	public function testTransientFailureIsNotCached(): void {
		$svc = $this->service();
		$svc->method('fetchProjectAddress')->willReturn(['street' => '1 Rue', 'city' => 'Paris', 'zip' => '75001']);
		$svc->method('lookupCache')->willReturn(null);
		$svc->method('callNominatim')->willReturn(null);
		$svc->expects($this->never())->method('insertCache');

		$result = $svc->geocodeProject(1);
		$this->assertSame('unavailable', $result['status']);
	}
}
