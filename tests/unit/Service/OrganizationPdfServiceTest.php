<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Service\OrganizationPdfService;
use OCP\Files\IAppData;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;

final class OrganizationPdfServiceTest extends TestCase {
	public function testNormalizePdfFileNameAddsExtension(): void {
		$service = $this->service();

		$this->assertSame('Project handbook.pdf', $service->normalizePdfFileName('Project handbook'));
	}

	public function testNormalizePdfFileNamePreservesExplicitExtension(): void {
		$service = $this->service();

		$this->assertSame('Contract.PDF', $service->normalizePdfFileName(' Contract.PDF '));
	}

	public function testNormalizePdfFileNameRejectsPathSeparators(): void {
		$service = $this->service();

		$this->expectException(\InvalidArgumentException::class);
		$service->normalizePdfFileName('../contract.pdf');
	}

	private function service(): OrganizationPdfService {
		return new OrganizationPdfService(
			$this->createMock(IAppData::class),
			$this->createMock(LoggerInterface::class),
		);
	}
}
