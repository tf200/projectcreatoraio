<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Tests\Unit\Db;

use OCA\ProjectCreatorAIO\Db\BoardPermissionProfile;
use PHPUnit\Framework\TestCase;

final class BoardPermissionProfileTest extends TestCase {
	public function testPayloadPreservesExplicitEmptyOverrideAndHasNoPortableIds(): void {
		$payload = ['cards' => [['ref' => 'card-1', 'stackRef' => 'stack-1', 'functionalPolicy' => [
			'view' => ['mode' => 'override', 'allowedFunctionalRoleKeys' => []],
		]]]];
		$profile = new BoardPermissionProfile();
		$profile->setPayloadJson(json_encode($payload, JSON_THROW_ON_ERROR));

		$this->assertSame($payload, $profile->getPayload());
		$this->assertArrayNotHasKey('id', $profile->getPayload()['cards'][0]);
		$this->assertSame([], $profile->getPayload()['cards'][0]['functionalPolicy']['view']['allowedFunctionalRoleKeys']);
	}
}
