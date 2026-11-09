<?php
declare(strict_types=1);

/**
 * DJ CRM Software — Central Platform Settings Service
 * Manages software-owner level settings: central AI keys, global WhatsApp support,
 * owner subscription payment details, and SaaS policies.
 */

if (!defined('YBT_ROOT')) exit;

class PlatformSettings {
    private static array $cache = [];

    /**
     * Get a setting by key with optional fallback
     */
    public static function get(string $key, ?string $default = null): ?string {
        if (isset(self::$cache[$key])) {
            return self::$cache[$key];
        }

        try {
            $val = Database::fetchColumn("SELECT `value` FROM `platform_settings` WHERE `key` = ?", [$key]);
            if ($val !== false && $val !== null) {
                self::$cache[$key] = (string)$val;
                return self::$cache[$key];
            }
        } catch (\Throwable $e) {}

        return $default;
    }

    /**
     * Set a platform setting (Owner / Super Admin only)
     */
    public static function set(string $key, string $value, ?string $description = null, bool $isSecret = false): bool {
        self::$cache[$key] = $value;
        $now = date('Y-m-d H:i:s');

        $exists = Database::fetchColumn("SELECT COUNT(*) FROM `platform_settings` WHERE `key` = ?", [$key]);
        if ($exists) {
            $update = ['value' => $value, 'updated_at' => $now];
            if ($description !== null) $update['description'] = $description;
            if ($isSecret) $update['is_secret'] = 1;
            Database::update('platform_settings', $update, '`key` = ?', [$key]);
            return true;
        } else {
            Database::insert('platform_settings', [
                'key' => $key,
                'value' => $value,
                'description' => $description ?? $key,
                'is_secret' => $isSecret ? 1 : 0,
                'updated_at' => $now
            ]);
            return true;
        }
    }

    /**
     * Get all platform settings
     */
    public static function getAll(): array {
        $rows = Database::fetchAll("SELECT * FROM `platform_settings` ORDER BY `key` ASC");
        $res = [];
        foreach ($rows as $r) {
            $res[$r['key']] = $r['value'];
        }
        return $res;
    }

    /**
     * Get central WhatsApp Support Number
     */
    public static function getWhatsAppSupport(): string {
        $stored = self::get('whatsapp_support_number');
        if (!empty($stored)) return $stored;
        return getenv('WHATSAPP_SUPPORT_NUMBER') ?: '923000000000';
    }

    /**
     * Get Owner Payment Methods for SaaS Subscriptions
     */
    public static function getOwnerPaymentMethods(): array {
        return [
            'bank_name' => self::get('owner_bank_name', 'Meezan Bank Limited'),
            'account_title' => self::get('owner_account_title', 'DJ CRM Software Services'),
            'account_number' => self::get('owner_account_number', '01020304050607'),
            'iban' => self::get('owner_iban', 'PK00MEZN01020304050607'),
            'easypaisa' => self::get('owner_easypaisa', '03001234567'),
            'jazzcash' => self::get('owner_jazzcash', '03001234567'),
            'raast_id' => self::get('owner_raast_id', '03001234567'),
            'upi_id' => self::get('owner_upi_id', 'djcrm@bank')
        ];
    }

    /**
     * Get Central AI Provider configuration (Decrypted, server-side only)
     */
    public static function getCentralAI(): array {
        $provider = self::get('central_ai_provider', 'google_gemini');
        $model = self::get('central_ai_model', 'gemini-1.5-flash');
        $baseUrl = self::get('central_ai_base_url', 'https://generativelanguage.googleapis.com/v1beta');
        $encryptedKey = self::get('central_ai_key_encrypted', '');

        $apiKey = '';
        if (!empty($encryptedKey) && function_exists('decryptString')) {
            $apiKey = decryptString($encryptedKey);
        }

        return [
            'provider' => $provider,
            'model' => $model,
            'base_url' => $baseUrl,
            'api_key' => $apiKey,
            'has_key' => !empty($apiKey),
            'masked_key' => !empty($apiKey) ? (strlen($apiKey) > 6 ? substr($apiKey, 0, 3) . '••••••••' . substr($apiKey, -4) : '••••••••') : 'None'
        ];
    }
}
