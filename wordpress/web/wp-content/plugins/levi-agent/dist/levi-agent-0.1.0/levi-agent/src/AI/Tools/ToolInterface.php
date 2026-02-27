<?php

namespace Levi\Agent\AI\Tools;

interface ToolInterface {
    /**
     * Get the tool name (used as function name for AI)
     */
    public function getName(): string;

    /**
     * Get the tool description (shown to AI)
     */
    public function getDescription(): string;

    /**
     * Get JSON Schema for tool parameters
     */
    public function getParameters(): array;

    /**
     * Execute the tool with given parameters
     * 
     * @param array $params Parameters from AI
     * @return array Result with 'success' boolean and data/error
     */
    public function execute(array $params): array;

    /**
     * Check if current user has permission to use this tool
     */
    public function checkPermission(): bool;
}
