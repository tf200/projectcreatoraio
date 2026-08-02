<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Service;

use OCA\ProjectCreatorAIO\Service\BoardPermissionProfileService;
use PHPUnit\Framework\TestCase;

final class BoardPermissionProfileServiceTest extends TestCase {
	public function testSchemaAcceptsExplicitEmptyOverride(): void {
		$payload = $this->validPayload();
		$payload['cards'][0]['functionalPolicy']['view'] = [
			'mode' => 'override',
			'allowedFunctionalRoleKeys' => [],
		];

		BoardPermissionProfileService::validatePayload($payload);
		$this->addToAssertionCount(1);
	}

	public function testSchemaRejectsUnknownFunctionalRoleReference(): void {
		$payload = $this->validPayload();
		$payload['cards'][0]['functionalPolicy']['move'] = [
			'mode' => 'override',
			'allowedFunctionalRoleKeys' => ['missing'],
		];

		$this->expectException(\InvalidArgumentException::class);
		BoardPermissionProfileService::validatePayload($payload);
	}

	public function testSchemaRejectsUnknownKeys(): void {
		$payload = $this->validPayload();
		$payload['cards'][0]['numericId'] = 42;

		$this->expectException(\InvalidArgumentException::class);
		BoardPermissionProfileService::validatePayload($payload);
	}

	private function validPayload(): array {
		$inherit = ['mode' => 'inherit', 'allowedFunctionalRoleKeys' => []];
		return [
			'stacks' => [[
				'ref' => 'stack-1',
				'title' => 'Inbox',
				'order' => 1,
				'approved' => false,
				'done' => false,
			]],
			'cards' => [[
				'ref' => 'card-1',
				'stackRef' => 'stack-1',
				'title' => 'Review',
				'description' => '',
				'order' => 1,
				'functionalPolicy' => [
					'view' => $inherit,
					'move' => $inherit,
					'sign' => $inherit,
					'verify' => $inherit,
				],
			]],
			'functionalRoles' => [['key' => 'reviewer', 'name' => 'Reviewer']],
			'drascivsDefaults' => [
				'view' => ['informed'],
				'move' => ['driver'],
				'sign' => ['signer'],
				'verify' => ['verifier'],
			],
		];
	}
}
