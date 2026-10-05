<?php

declare(strict_types=1);

namespace OCA\ProjectCreatorAIO\Service;

use DateTime;

/**
 * Backward pass over an already scheduled timeline: how late each task may finish
 * without moving the deadline, how much slack that leaves, and which tasks drive it.
 */
class TimelineCriticalPath
{
	/**
	 * @param array<string, array{startDate: string, endDate: string, isDone?: bool, predecessorIds?: array<int, int|string>}> $tasks Keyed by task id
	 * @param array<string, int> $dependencyOverlaps Keyed by TimelinePhaseService::dependencyKey()
	 * @param DateTime|null $deadline Latest allowed finish for tasks without successors; the latest scheduled finish when null
	 * @return array<string, array{lateStart: string|null, lateFinish: string|null, floatDays: int|null, isCritical: bool}>
	 */
	public function analyze(array $tasks, array $dependencyOverlaps = [], ?DateTime $deadline = null): array
	{
		$starts = [];
		$ends = [];
		$successors = [];
		foreach ($tasks as $id => $task) {
			$id = (string)$id;
			$starts[$id] = $this->date($task['startDate']);
			$ends[$id] = $this->date($task['endDate']);
			$successors[$id] ??= [];
			foreach ($task['predecessorIds'] ?? [] as $predecessorId) {
				$successors[(string)$predecessorId][] = $id;
			}
		}

		if ($deadline === null) {
			$deadline = $ends === [] ? new DateTime('today') : max($ends);
		}
		$deadline = (clone $deadline)->setTime(0, 0);

		$lateFinish = [];
		$visiting = [];
		$resolve = function (string $id) use (&$resolve, &$lateFinish, &$visiting, $tasks, $starts, $ends, $successors, $dependencyOverlaps, $deadline): DateTime {
			if (isset($lateFinish[$id])) {
				return $lateFinish[$id];
			}
			if (isset($visiting[$id])) {
				throw new \RuntimeException('Timeline dependencies contain a cycle');
			}
			if (!empty($tasks[$id]['isDone'])) {
				return $lateFinish[$id] = clone $ends[$id];
			}
			$visiting[$id] = true;

			$latest = null;
			foreach ($successors[$id] ?? [] as $successorId) {
				if (!isset($tasks[$successorId]) || !empty($tasks[$successorId]['isDone'])) {
					continue;
				}
				// The successor's latest start, minus one day, plus any overlap it may start with.
				$candidate = (clone $resolve($successorId))
					->modify('-' . $this->durationDays($starts[$successorId], $ends[$successorId]) . ' days')
					->modify('+' . max(0, (int)($dependencyOverlaps[TimelinePhaseService::dependencyKey($id, $successorId)] ?? 0)) . ' days');
				if ($latest === null || $candidate < $latest) {
					$latest = $candidate;
				}
			}

			unset($visiting[$id]);
			return $lateFinish[$id] = $latest ?? clone $deadline;
		};

		$result = [];
		$minFloat = null;
		foreach (array_keys($tasks) as $id) {
			$id = (string)$id;
			if (!empty($tasks[$id]['isDone'])) {
				$result[$id] = ['lateStart' => null, 'lateFinish' => null, 'floatDays' => null, 'isCritical' => false];
				continue;
			}
			$finish = $resolve($id);
			$start = (clone $finish)->modify('-' . ($this->durationDays($starts[$id], $ends[$id]) - 1) . ' days');
			$float = $this->signedDays($ends[$id], $finish);
			$result[$id] = [
				'lateStart' => $start->format('Y-m-d'),
				'lateFinish' => $finish->format('Y-m-d'),
				'floatDays' => $float,
				'isCritical' => false,
			];
			$minFloat = $minFloat === null ? $float : min($minFloat, $float);
		}

		// The critical path is the chain with the least slack: any delay on it moves the deadline.
		foreach ($result as $id => $analysis) {
			if ($analysis['floatDays'] !== null && $analysis['floatDays'] === $minFloat) {
				$result[$id]['isCritical'] = true;
			}
		}

		return $result;
	}

	private function date(string $value): DateTime
	{
		return (new DateTime($value))->setTime(0, 0);
	}

	private function durationDays(DateTime $start, DateTime $end): int
	{
		return max(1, $this->signedDays($start, $end) + 1);
	}

	private function signedDays(DateTime $from, DateTime $to): int
	{
		$days = (int)$from->diff($to)->days;
		return $to < $from ? -$days : $days;
	}
}
