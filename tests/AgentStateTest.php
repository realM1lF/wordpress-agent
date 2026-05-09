<?php
/**
 * Agent State Machine Test
 *
 * Validates the ORPA state transitions, labels, and visibility flags.
 * Pure PHP — no WordPress or AI calls required.
 *
 * Run: php tests/AgentStateTest.php
 * Or inside DDEV: ddev exec php wp-content/plugins/levi-agent/tests/AgentStateTest.php
 */

require_once dirname(__DIR__) . "/src/AI/Tools/AgentState.php";

use Levi\Agent\AI\Tools\AgentState;

class AgentStateTest
{
    private int $passed = 0;
    private int $failed = 0;
    private array $failures = [];

    public function run(): int
    {
        echo "=== Agent State Machine Test ===\n\n";

        $this->testAllStatesHaveLabel();
        $this->testAllStatesHaveVisibility();
        $this->testValidTransitions();
        $this->testInvalidTransitions();
        $this->testORPALoop();
        $this->testErrorRecovery();

        echo "\n=== Results ===\n";
        echo "Passed: {$this->passed}\n";
        echo "Failed: {$this->failed}\n\n";

        if (!empty($this->failures)) {
            echo "--- FAILURES ---\n";
            foreach ($this->failures as $f) {
                echo "  FAIL: $f\n";
            }
            echo "\n";
        }

        return $this->failed > 0 ? 1 : 0;
    }

    // =====================================================================
    // Tests
    // =====================================================================

    private function testAllStatesHaveLabel(): void
    {
        foreach (AgentState::cases() as $state) {
            $label = $state->label();
            if ($label !== "" && $label !== $state->value) {
                $this->pass("{$state->value}: has label '{$label}'");
            } else {
                $this->fail(
                    "{$state->value}: label is empty or identical to value",
                );
            }
        }
    }

    private function testAllStatesHaveVisibility(): void
    {
        foreach (AgentState::cases() as $state) {
            // isVisible() returns bool — just verify it doesn't throw
            try {
                $visible = $state->isVisible();
                $this->pass(
                    "{$state->value}: isVisible = " .
                        ($visible ? "true" : "false"),
                );
            } catch (\Throwable $e) {
                $this->fail("{$state->value}: isVisible() threw exception");
            }
        }
    }

    private function testValidTransitions(): void
    {
        $validTransitions = [
            [AgentState::IDLE, AgentState::OBSERVING],
            [AgentState::OBSERVING, AgentState::REASONING],
            [AgentState::OBSERVING, AgentState::PLANNING],
            [AgentState::OBSERVING, AgentState::DONE],
            [AgentState::OBSERVING, AgentState::ERROR],
            [AgentState::REASONING, AgentState::PLANNING],
            [AgentState::REASONING, AgentState::EXECUTING],
            [AgentState::REASONING, AgentState::DONE],
            [AgentState::REASONING, AgentState::ERROR],
            [AgentState::PLANNING, AgentState::EXECUTING],
            [AgentState::PLANNING, AgentState::IDLE],
            [AgentState::PLANNING, AgentState::ERROR],
            [AgentState::EXECUTING, AgentState::VERIFYING],
            [AgentState::EXECUTING, AgentState::REASONING],
            [AgentState::EXECUTING, AgentState::ERROR],
            [AgentState::VERIFYING, AgentState::DONE],
            [AgentState::VERIFYING, AgentState::EXECUTING],
            [AgentState::VERIFYING, AgentState::ERROR],
            [AgentState::DONE, AgentState::IDLE],
            [AgentState::ERROR, AgentState::IDLE],
        ];

        foreach ($validTransitions as [$from, $to]) {
            if ($from->canTransitionTo($to)) {
                $this->pass("{$from->value} → {$to->value}: valid transition");
            } else {
                $this->fail(
                    "{$from->value} → {$to->value}: should be valid but was rejected",
                );
            }
        }
    }

    private function testInvalidTransitions(): void
    {
        $invalidTransitions = [
            [AgentState::IDLE, AgentState::EXECUTING],
            [AgentState::IDLE, AgentState::DONE],
            [AgentState::OBSERVING, AgentState::EXECUTING],
            [AgentState::PLANNING, AgentState::OBSERVING],
            [AgentState::REASONING, AgentState::OBSERVING],
            [AgentState::PLANNING, AgentState::OBSERVING],
            [AgentState::EXECUTING, AgentState::IDLE],
            [AgentState::VERIFYING, AgentState::IDLE],
            [AgentState::DONE, AgentState::EXECUTING],
            [AgentState::ERROR, AgentState::DONE],
            [AgentState::DONE, AgentState::ERROR],
        ];

        foreach ($invalidTransitions as [$from, $to]) {
            if (!$from->canTransitionTo($to)) {
                $this->pass(
                    "{$from->value} → {$to->value}: correctly rejected",
                );
            } else {
                $this->fail(
                    "{$from->value} → {$to->value}: should be invalid but was allowed",
                );
            }
        }
    }

    private function testORPALoop(): void
    {
        // Simulate a full ORPA loop for a complex task
        $path = [
            AgentState::IDLE,
            AgentState::OBSERVING,
            AgentState::REASONING,
            AgentState::PLANNING,
            AgentState::EXECUTING,
            AgentState::VERIFYING,
            AgentState::DONE,
            AgentState::IDLE,
        ];

        $valid = true;
        for ($i = 0; $i < count($path) - 1; $i++) {
            if (!$path[$i]->canTransitionTo($path[$i + 1])) {
                $this->fail(
                    "ORPA loop broken at {$path[$i]->value} → {$path[$i +
                            1]->value}",
                );
                $valid = false;
                break;
            }
        }

        if ($valid) {
            $this->pass(
                "Full ORPA loop (IDLE→OBSERVING→REASONING→PLANNING→EXECUTING→VERIFYING→DONE→IDLE) is valid",
            );
        }
    }

    private function testErrorRecovery(): void
    {
        // Any state can go to ERROR
        $states = [
            AgentState::IDLE,
            AgentState::OBSERVING,
            AgentState::REASONING,
            AgentState::PLANNING,
            AgentState::EXECUTING,
            AgentState::VERIFYING,
        ];

        foreach ($states as $state) {
            // Only specific states can go to ERROR per the enum
            $canError = $state->canTransitionTo(AgentState::ERROR);
            $expected = in_array(
                $state,
                [
                    AgentState::OBSERVING,
                    AgentState::REASONING,
                    AgentState::PLANNING,
                    AgentState::EXECUTING,
                    AgentState::VERIFYING,
                ],
                true,
            );

            if ($canError === $expected) {
                $this->pass(
                    "{$state->value} → error: " .
                        ($canError ? "allowed" : "not allowed") .
                        " (correct)",
                );
            } else {
                $this->fail(
                    "{$state->value} → error: expected " .
                        ($expected ? "allowed" : "not allowed") .
                        ", got " .
                        ($canError ? "allowed" : "not allowed"),
                );
            }
        }

        // ERROR can only go to IDLE
        if (AgentState::ERROR->canTransitionTo(AgentState::IDLE)) {
            $this->pass("ERROR → IDLE: allowed (correct)");
        } else {
            $this->fail("ERROR → IDLE: should be allowed");
        }

        if (!AgentState::ERROR->canTransitionTo(AgentState::OBSERVING)) {
            $this->pass("ERROR → OBSERVING: correctly rejected");
        } else {
            $this->fail("ERROR → OBSERVING: should be rejected");
        }
    }

    // =====================================================================
    // Helpers
    // =====================================================================

    private function pass(string $msg): void
    {
        $this->passed++;
        echo "  PASS: $msg\n";
    }

    private function fail(string $msg): void
    {
        $this->failed++;
        $this->failures[] = $msg;
        echo "  FAIL: $msg\n";
    }
}

$test = new AgentStateTest();
exit($test->run());
