<?php

namespace Levi\Agent\AI;

class PIIRedactor {

    private static ?self $instance = null;

    private bool $enabled;
    private array $blockedPostTypes;
    private array $blockedMetaPatterns;

    private const DEFAULT_BLOCKED_POST_TYPES = [
        'wpforms_entry',
        'flamingo_inbound',
        'flamingo_outbound',
        'nf_sub',
        'forminator_forms',
        'forminator_submissions',
        'frm_display',
        'frm_form_actions',
        'edd_payment',
        'edd_discount',
        'shop_subscription',
        'tribe_rsvp',
        'mc4wp_form',
        'shop_coupon',
    ];

    private const BLOCKED_META_PREFIXES = [
        '_billing_',
        '_shipping_',
        '_customer_',
        '_stripe_',
        '_paypal_',
        '_mollie_',
        '_klarna_',
    ];

    private const BLOCKED_META_EXACT = [
        '_transaction_id',
        '_payment_method_token',
        '_cart_hash',
    ];

    private const BLOCKED_META_KEYWORDS = [
        'password',
        'secret',
        'api_key',
        'apikey',
        'access_token',
        'private_key',
        'auth_token',
    ];

    public function __construct(array $settings = []) {
        $this->enabled = (bool) ($settings['pii_redaction'] ?? true);

        $custom = array_filter(array_map('trim', explode("\n", (string) ($settings['blocked_post_types'] ?? ''))));
        $this->blockedPostTypes = array_unique(array_merge(self::DEFAULT_BLOCKED_POST_TYPES, $custom));

        $this->blockedMetaPatterns = [];
        foreach (self::BLOCKED_META_PREFIXES as $prefix) {
            $this->blockedMetaPatterns[] = '/^' . preg_quote($prefix, '/') . '/i';
        }
        foreach (self::BLOCKED_META_KEYWORDS as $kw) {
            $this->blockedMetaPatterns[] = '/' . preg_quote($kw, '/') . '/i';
        }
    }

    public static function init(array $settings): void {
        self::$instance = new self($settings);
    }

    public static function getInstance(): self {
        if (self::$instance === null) {
            self::$instance = new self();
        }
        return self::$instance;
    }

    public function isEnabled(): bool {
        return $this->enabled;
    }

    public function isBlockedPostType(string $postType): bool {
        if (!$this->enabled) {
            return false;
        }
        return in_array($postType, $this->blockedPostTypes, true);
    }

    public function isBlockedMetaKey(string $metaKey): bool {
        if (!$this->enabled) {
            return false;
        }
        foreach (self::BLOCKED_META_EXACT as $exact) {
            if (strcasecmp($metaKey, $exact) === 0) {
                return true;
            }
        }
        foreach ($this->blockedMetaPatterns as $pattern) {
            if (preg_match($pattern, $metaKey) === 1) {
                return true;
            }
        }
        return false;
    }

    /**
     * Redact PII patterns from a string. Fast regex-based, no external deps.
     */
    public function redact(string $text): string {
        if (!$this->enabled || $text === '') {
            return $text;
        }

        $text = $this->redactEmails($text);
        $text = $this->redactPhoneNumbers($text);
        $text = $this->redactIBANs($text);
        $text = $this->redactCreditCards($text);

        return $text;
    }

    private function redactEmails(string $text): string {
        return preg_replace_callback(
            '/\b([A-Za-z0-9._%+\-]{1,64})@([A-Za-z0-9.\-]+\.[A-Za-z]{2,})\b/',
            function ($m) {
                $local = $m[1];
                $domain = $m[2];
                $tld = substr($domain, strrpos($domain, '.'));
                return $local[0] . '***@' . $domain[0] . '***' . $tld;
            },
            $text
        ) ?? $text;
    }

    private function redactPhoneNumbers(string $text): string {
        // International format: +49 171 1234567 or +49-171-1234567
        $text = preg_replace_callback(
            '/\+\d{1,3}[\s\-.]?\(?\d{2,5}\)?[\s\-.]?\d{2,5}[\s\-.]?\d{2,7}/',
            function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);
                if (strlen($digits) < 7) {
                    return $m[0];
                }
                return substr($m[0], 0, 3) . ' *** ***' . substr($digits, -3);
            },
            $text
        ) ?? $text;

        // German domestic: 0171 1234567, (030) 123456, 0171/1234567
        $text = preg_replace_callback(
            '/(?<=\s|^|\()\(?0\d{2,5}\)?[\s\-\.\/]\d{2,5}[\s\-.]?\d{2,7}/',
            function ($m) {
                $digits = preg_replace('/\D/', '', $m[0]);
                if (strlen($digits) < 7) {
                    return $m[0];
                }
                return substr($m[0], 0, 4) . ' ***' . substr($digits, -3);
            },
            $text
        ) ?? $text;

        return $text;
    }

    private function redactIBANs(string $text): string {
        return preg_replace_callback(
            '/\b([A-Z]{2}\d{2})[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{4}[\s]?\d{0,4}\b/',
            function ($m) {
                return $m[1] . ' **** **** ****';
            },
            $text
        ) ?? $text;
    }

    private function redactCreditCards(string $text): string {
        return preg_replace_callback(
            '/\b(\d{4})[\s\-]?\d{4}[\s\-]?\d{4}[\s\-]?(\d{1,7})\b/',
            function ($m) {
                $full = preg_replace('/\D/', '', $m[0]);
                if (strlen($full) < 13 || strlen($full) > 19) {
                    return $m[0];
                }
                return '**** **** **** ' . substr($full, -4);
            },
            $text
        ) ?? $text;
    }

    /**
     * Filter an associative array of meta keys, removing blocked ones.
     * Returns the filtered array plus a list of redacted key names.
     */
    public function filterMetaKeys(array $meta): array {
        $filtered = [];
        $redacted = [];
        foreach ($meta as $key => $value) {
            if ($this->isBlockedMetaKey($key)) {
                $redacted[] = $key;
                continue;
            }
            $filtered[$key] = $value;
        }
        return ['meta' => $filtered, 'redacted_keys' => $redacted];
    }
}
