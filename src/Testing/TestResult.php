<?php

namespace Levi\Agent\Testing;

class TestResult {
    public string $name;
    public string $status = 'pending';
    /** @var array<int, array{assertion: string, passed: bool, detail: string}> */
    public array $assertions = [];
    /** @var array<int, array{type: string, data: array}> */
    public array $log = [];
    public float $durationSeconds = 0.0;
    public ?string $error = null;

    public function __construct(string $name) {
        $this->name = $name;
    }

    public function addAssertion(string $assertion, bool $passed, string $detail = ''): void {
        $this->assertions[] = [
            'assertion' => $assertion,
            'passed' => $passed,
            'detail' => $detail,
        ];
    }

    public function appendLog(string $type, array $data): void {
        $this->log[] = ['type' => $type, 'data' => $data, 'time' => microtime(true)];
    }

    public function passed(): bool {
        if ($this->status === 'error') {
            return false;
        }
        foreach ($this->assertions as $a) {
            if (!$a['passed']) {
                return false;
            }
        }
        return !empty($this->assertions);
    }

    public function failedCount(): int {
        return count(array_filter($this->assertions, fn($a) => !$a['passed']));
    }

    public function passedCount(): int {
        return count(array_filter($this->assertions, fn($a) => $a['passed']));
    }
}
