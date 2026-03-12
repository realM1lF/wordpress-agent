<?php

namespace Levi\Agent\Admin;

class OpenRouterOAuth {
    private const AUTH_URL = 'https://openrouter.ai/auth';
    private const TOKEN_URL = 'https://openrouter.ai/api/v1/auth/keys';
    private const VERIFIER_TRANSIENT_PREFIX = 'levi_oauth_verifier_';
    private const VERIFIER_TTL = 600; // 10 minutes

    private string $settingsOption = 'levi_agent_settings';

    public function __construct() {
        add_action('admin_init', [$this, 'maybeHandleCallback']);
        add_action('wp_ajax_levi_oauth_disconnect', [$this, 'ajaxDisconnect']);
    }

    /**
     * @param string $source 'settings' or 'wizard' — stored in transient so the
     *                       callback knows where to redirect after the exchange.
     */
    public function getAuthUrl(string $source = 'settings'): string {
        $userId = get_current_user_id();
        $verifier = $this->generateCodeVerifier();
        $challenge = $this->generateCodeChallenge($verifier);

        set_transient(
            self::VERIFIER_TRANSIENT_PREFIX . $userId,
            ['verifier' => $verifier, 'source' => $source],
            self::VERIFIER_TTL
        );

        $callbackUrl = admin_url('admin.php');

        return add_query_arg([
            'callback_url' => $callbackUrl,
            'code_challenge' => $challenge,
            'code_challenge_method' => 'S256',
        ], self::AUTH_URL);
    }

    public function maybeHandleCallback(): void {
        if (empty($_GET['code'])) {
            return;
        }

        if (!is_admin() || !current_user_can('manage_options')) {
            return;
        }

        $userId = get_current_user_id();
        $transientKey = self::VERIFIER_TRANSIENT_PREFIX . $userId;
        $stored = get_transient($transientKey);

        if ($stored === false || !is_array($stored) || empty($stored['verifier'])) {
            return;
        }

        $code = sanitize_text_field(wp_unslash($_GET['code']));
        $verifier = $stored['verifier'];
        $isWizard = ($stored['source'] ?? 'settings') === 'wizard';

        delete_transient($transientKey);

        $apiKey = $this->exchangeCodeForKey($code, $verifier);
        if (is_wp_error($apiKey)) {
            $this->redirectWithError('exchange_failed', $apiKey->get_error_message(), $isWizard);
            return;
        }

        $this->saveOAuthKey($apiKey);

        if ($isWizard) {
            $this->saveWizardDefaults();
            wp_safe_redirect(
                admin_url('admin.php?page=levi-agent-setup-wizard&step=3&saved=pro')
            );
            exit;
        }

        $this->redirectWithSuccess();
    }

    public function ajaxDisconnect(): void {
        check_ajax_referer('levi_admin_nonce', 'nonce');

        if (!current_user_can('manage_options')) {
            wp_send_json_error(['message' => 'Unauthorized'], 403);
            return;
        }

        $this->disconnect();
        wp_send_json_success(['message' => 'Disconnected']);
    }

    public function disconnect(): void {
        $settings = get_option($this->settingsOption, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['openrouter_api_key'] = '';
        $settings['ai_auth_method'] = 'api_key';
        $settings['oauth_connected_at'] = null;

        update_option($this->settingsOption, $settings);
    }

    public function isOAuthConnected(): bool {
        $settings = get_option($this->settingsOption, []);
        if (!is_array($settings)) {
            return false;
        }

        return ($settings['ai_auth_method'] ?? '') === 'oauth'
            && !empty($settings['openrouter_api_key'])
            && !empty($settings['oauth_connected_at']);
    }

    private function exchangeCodeForKey(string $code, string $verifier): string|\WP_Error {
        $response = wp_remote_post(self::TOKEN_URL, [
            'headers' => ['Content-Type' => 'application/json'],
            'body' => wp_json_encode([
                'code' => $code,
                'code_verifier' => $verifier,
                'code_challenge_method' => 'S256',
            ]),
            'timeout' => 15,
        ]);

        if (is_wp_error($response)) {
            return $response;
        }

        $status = wp_remote_retrieve_response_code($response);
        $body = json_decode(wp_remote_retrieve_body($response), true);

        if ($status !== 200 || !is_array($body)) {
            $errorMsg = $body['error'] ?? $body['message'] ?? 'Unknown error (HTTP ' . $status . ')';
            return new \WP_Error('oauth_exchange_failed', $errorMsg);
        }

        $key = $body['key'] ?? '';
        if ($key === '') {
            return new \WP_Error('oauth_no_key', 'No API key in response');
        }

        return $key;
    }

    private function saveOAuthKey(string $apiKey): void {
        $settings = get_option($this->settingsOption, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['openrouter_api_key'] = sanitize_text_field($apiKey);
        $settings['ai_auth_method'] = 'oauth';
        $settings['oauth_connected_at'] = time();

        update_option($this->settingsOption, $settings);
    }

    private function generateCodeVerifier(): string {
        return rtrim(strtr(base64_encode(random_bytes(64)), '+/', '-_'), '=');
    }

    private function generateCodeChallenge(string $verifier): string {
        $hash = hash('sha256', $verifier, true);
        return rtrim(strtr(base64_encode($hash), '+/', '-_'), '=');
    }

    /**
     * After a successful OAuth flow from the wizard, apply the same defaults
     * that handleSaveProSetup() would set for manual key entry.
     */
    private function saveWizardDefaults(): void {
        $settings = get_option($this->settingsOption, []);
        if (!is_array($settings)) {
            $settings = [];
        }

        $settings['openrouter_model'] = $settings['openrouter_model'] ?? 'moonshotai/kimi-k2.5';
        $settings['tool_profile'] = $settings['tool_profile'] ?? 'standard';
        $settings['require_confirmation_destructive'] = $settings['require_confirmation_destructive'] ?? 1;
        $settings['max_tool_iterations'] = $settings['max_tool_iterations'] ?? 25;
        $settings['history_context_limit'] = $settings['history_context_limit'] ?? 20;

        update_option($this->settingsOption, $settings);
        update_option('levi_plan_tier', 'pro');
    }

    private function redirectWithError(string $errorCode, string $details = '', bool $isWizard = false): void {
        if ($isWizard) {
            $url = admin_url('admin.php?page=levi-agent-setup-wizard&step=2&oauth_error=' . $errorCode);
        } else {
            $url = admin_url('admin.php?page=levi-agent-settings&tab=ai-provider&oauth_error=' . $errorCode);
        }
        if ($details !== '') {
            $url = add_query_arg('oauth_details', urlencode($details), $url);
        }
        wp_safe_redirect($url);
        exit;
    }

    private function redirectWithSuccess(): void {
        wp_safe_redirect(
            admin_url('admin.php?page=levi-agent-settings&tab=ai-provider&oauth_success=1')
        );
        exit;
    }
}
