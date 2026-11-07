<?php
/**
 * YBT Management Software — AI Provider Service
 * Multi-provider API integration for Google AI Studio, OpenRouter, OpenAI, Anthropic, and Custom endpoints.
 */

if (!defined('YBT_ROOT')) exit;

class AIProviderService {
    /**
     * Supported provider definitions & default parameters
     */
    public static function getPresets(): array {
        return [
            'google_gemini' => [
                'name' => 'Google AI Studio (Gemini)',
                'icon' => 'ti-brand-google',
                'badge' => 'Google AI',
                'default_base_url' => 'https://generativelanguage.googleapis.com/v1beta',
                'default_model' => 'gemini-1.5-flash',
                'suggested_models' => [
                    'gemini-1.5-flash' => 'Gemini 1.5 Flash (Fast & Recommended)',
                    'gemini-1.5-pro' => 'Gemini 1.5 Pro (Complex Reasoning)',
                    'gemini-2.0-flash-exp' => 'Gemini 2.0 Flash Experimental'
                ],
                'api_key_placeholder' => 'AIzaSy...',
                'docs_url' => 'https://aistudio.google.com/app/apikey',
                'auth_type' => 'query_param' // ?key={API_KEY}
            ],
            'openrouter' => [
                'name' => 'OpenRouter (Unified API)',
                'icon' => 'ti-route',
                'badge' => 'OpenRouter',
                'default_base_url' => 'https://openrouter.ai/api/v1',
                'default_model' => 'google/gemini-flash-1.5',
                'suggested_models' => [
                    'google/gemini-flash-1.5' => 'Google: Gemini Flash 1.5',
                    'openai/gpt-4o-mini' => 'OpenAI: GPT-4o Mini',
                    'anthropic/claude-3.5-sonnet' => 'Anthropic: Claude 3.5 Sonnet',
                    'deepseek/deepseek-chat' => 'DeepSeek: DeepSeek Chat V3',
                    'meta-llama/llama-3.3-70b-instruct' => 'Meta: Llama 3.3 70B'
                ],
                'api_key_placeholder' => 'sk-or-v1-...',
                'docs_url' => 'https://openrouter.ai/keys',
                'auth_type' => 'bearer'
            ],
            'openai' => [
                'name' => 'OpenAI (ChatGPT)',
                'icon' => 'ti-brand-openai',
                'badge' => 'OpenAI',
                'default_base_url' => 'https://api.openai.com/v1',
                'default_model' => 'gpt-4o-mini',
                'suggested_models' => [
                    'gpt-4o-mini' => 'GPT-4o Mini (Fast & Cost Efficient)',
                    'gpt-4o' => 'GPT-4o (Flagship Multimodal)',
                    'gpt-3.5-turbo' => 'GPT-3.5 Turbo'
                ],
                'api_key_placeholder' => 'sk-proj-...',
                'docs_url' => 'https://platform.openai.com/api-keys',
                'auth_type' => 'bearer'
            ],
            'anthropic' => [
                'name' => 'Anthropic Claude',
                'icon' => 'ti-sparkles',
                'badge' => 'Anthropic',
                'default_base_url' => 'https://api.anthropic.com/v1',
                'default_model' => 'claude-3-5-sonnet-20241022',
                'suggested_models' => [
                    'claude-3-5-sonnet-20241022' => 'Claude 3.5 Sonnet (State-of-the-Art)',
                    'claude-3-5-haiku-20241022' => 'Claude 3.5 Haiku (Lightning Fast)'
                ],
                'api_key_placeholder' => 'sk-ant-api03-...',
                'docs_url' => 'https://console.anthropic.com/settings/keys',
                'auth_type' => 'anthropic_header'
            ],
            'custom' => [
                'name' => 'Custom OpenAI-Compatible API (Ollama / Local / Groq)',
                'icon' => 'ti-server',
                'badge' => 'Custom Endpoint',
                'default_base_url' => 'http://localhost:11434/v1',
                'default_model' => 'llama3',
                'suggested_models' => [
                    'llama3' => 'Ollama Llama 3',
                    'mistral' => 'Mistral 7B',
                    'qwen2.5' => 'Qwen 2.5'
                ],
                'api_key_placeholder' => 'Optional API Key (or leave blank if local)',
                'docs_url' => 'https://ollama.com/',
                'auth_type' => 'bearer'
            ]
        ];
    }

    /**
     * Get all configured providers for a business
     */
    public static function getAll(int $businessId): array {
        $rows = Database::fetchAll(
            "SELECT * FROM ai_provider_configs WHERE business_id = ? ORDER BY is_default DESC, is_active DESC, created_at DESC",
            [$businessId]
        );

        foreach ($rows as &$row) {
            $row['has_key'] = !empty($row['api_key_encrypted']);
            // Mask key for safety (only display last 4 characters)
            if ($row['has_key']) {
                $decrypted = decryptString($row['api_key_encrypted']);
                $len = strlen($decrypted);
                $row['masked_key'] = $len > 6 ? substr($decrypted, 0, 3) . '••••••••' . substr($decrypted, -4) : '••••••••';
            } else {
                $row['masked_key'] = 'None';
            }
        }
        return $rows;
    }

    /**
     * Get active default provider config for business
     */
    public static function getActive(int $businessId): ?array {
        $row = Database::fetch(
            "SELECT * FROM ai_provider_configs WHERE business_id = ? AND is_active = 1 AND is_default = 1 LIMIT 1",
            [$businessId]
        );

        if (!$row) {
            // Fallback to any active provider
            $row = Database::fetch(
                "SELECT * FROM ai_provider_configs WHERE business_id = ? AND is_active = 1 LIMIT 1",
                [$businessId]
            );
        }

        if ($row && !empty($row['api_key_encrypted'])) {
            $row['api_key'] = decryptString($row['api_key_encrypted']);
            $row['has_key'] = true;
            $len = strlen($row['api_key']);
            $row['masked_key'] = $len > 6 ? substr($row['api_key'], 0, 3) . '••••••••' . substr($row['api_key'], -4) : '••••••••';
            return $row;
        }

        // Fallback to Central Platform AI Provider configured by Super Admin
        if (class_exists('PlatformSettings')) {
            $central = PlatformSettings::getCentralAI();
            if (!empty($central['has_key'])) {
                return [
                    'id' => 0,
                    'business_id' => 0,
                    'provider_type' => $central['provider'],
                    'display_name' => 'DJ CRM Central AI (' . ucfirst(str_replace('_', ' ', $central['provider'])) . ')',
                    'model_id' => $central['model'],
                    'base_url' => $central['base_url'],
                    'api_key' => $central['api_key'],
                    'has_key' => true,
                    'masked_key' => $central['masked_key'],
                    'temperature' => 0.7,
                    'max_tokens' => 4096,
                    'is_active' => 1,
                    'is_default' => 1
                ];
            }
        }

        if ($row) {
            $row['api_key'] = '';
            $row['has_key'] = false;
            $row['masked_key'] = 'None';
        }

        return $row;
    }

    /**
     * Save (create or update) provider config
     */
    public static function save(int $businessId, array $data): int {
        $id = (int)($data['id'] ?? 0);
        $providerType = trim($data['provider_type'] ?? 'google_gemini');
        $displayName = trim($data['display_name'] ?? '');
        $modelId = trim($data['model_id'] ?? '');
        $baseUrl = trim($data['base_url'] ?? '');
        $rawApiKey = trim($data['api_key'] ?? '');
        $temperature = max(0.0, min(1.0, (float)($data['temperature'] ?? 0.7)));
        $maxTokens = max(256, min(16384, (int)($data['max_tokens'] ?? 4096)));
        $isActive = !empty($data['is_active']) ? 1 : 0;
        $isDefault = !empty($data['is_default']) ? 1 : 0;

        $presets = self::getPresets();
        if (empty($displayName) && isset($presets[$providerType])) {
            $displayName = $presets[$providerType]['name'];
        }
        if (empty($baseUrl) && isset($presets[$providerType])) {
            $baseUrl = $presets[$providerType]['default_base_url'];
        }
        if (empty($modelId) && isset($presets[$providerType])) {
            $modelId = $presets[$providerType]['default_model'];
        }

        // If marked default, unset any existing default for this business
        if ($isDefault) {
            Database::query(
                "UPDATE ai_provider_configs SET is_default = 0 WHERE business_id = ?",
                [$businessId]
            );
        }

        $record = [
            'business_id' => $businessId,
            'provider_type' => $providerType,
            'display_name' => $displayName,
            'base_url' => $baseUrl,
            'model_id' => $modelId,
            'temperature' => $temperature,
            'max_tokens' => $maxTokens,
            'is_active' => $isActive,
            'is_default' => $isDefault
        ];

        // Encrypt and store API key if provided
        if (!empty($rawApiKey)) {
            $record['api_key_encrypted'] = encryptString($rawApiKey);
        }

        if ($id > 0) {
            // Update existing
            Database::update('ai_provider_configs', $record, 'id = ? AND business_id = ?', [$id, $businessId]);
            return $id;
        } else {
            // Insert new
            return Database::insert('ai_provider_configs', $record);
        }
    }

    /**
     * Delete provider config
     */
    public static function delete(int $id, int $businessId): bool {
        return Database::delete('ai_provider_configs', 'id = ? AND business_id = ?', [$id, $businessId]) > 0;
    }

    /**
     * Test API connection with a lightweight prompt
     */
    public static function testConnection(string $providerType, string $apiKey, string $modelId, ?string $baseUrl = null): array {
        $startTime = microtime(true);
        $testPrompt = "Hello! Please reply with exactly: 'OK: Connected successfully.'";

        $mockConfig = [
            'provider_type' => $providerType,
            'api_key' => $apiKey,
            'model_id' => $modelId,
            'base_url' => $baseUrl,
            'temperature' => 0.2,
            'max_tokens' => 50
        ];

        $res = self::executeChat($mockConfig, "You are a connectivity test probe. Respond concisely.", $testPrompt);
        $latency = round((microtime(true) - $startTime) * 1000);

        if ($res['success']) {
            return [
                'success' => true,
                'message' => 'Connected successfully! Model responded: ' . htmlspecialchars(substr($res['text'], 0, 150)),
                'latency_ms' => $latency
            ];
        } else {
            return [
                'success' => false,
                'error' => $res['error'] ?? 'Connection test failed.',
                'latency_ms' => $latency
            ];
        }
    }

    /**
     * Execute chat request against the chosen provider
     */
    public static function executeChat(array $config, string $systemPrompt, string $userMessage, array $conversationHistory = []): array {
        $providerType = $config['provider_type'] ?? 'google_gemini';
        $apiKey = $config['api_key'] ?? '';
        $model = $config['model_id'] ?? 'gemini-1.5-flash';
        $baseUrl = rtrim($config['base_url'] ?? '', '/');
        $temperature = (float)($config['temperature'] ?? 0.7);
        $maxTokens = (int)($config['max_tokens'] ?? 2048);

        switch ($providerType) {
            case 'google_gemini':
                return self::callGoogleGemini($apiKey, $model, $baseUrl, $systemPrompt, $userMessage, $temperature, $maxTokens);

            case 'openrouter':
                return self::callOpenRouter($apiKey, $model, $baseUrl, $systemPrompt, $userMessage, $temperature, $maxTokens);

            case 'openai':
            case 'custom':
                return self::callOpenAiCompatible($apiKey, $model, $baseUrl, $systemPrompt, $userMessage, $temperature, $maxTokens);

            case 'anthropic':
                return self::callAnthropic($apiKey, $model, $baseUrl, $systemPrompt, $userMessage, $temperature, $maxTokens);

            default:
                return ['success' => false, 'error' => "Unsupported AI provider type: {$providerType}"];
        }
    }

    /* ============================================================
       Internal HTTP Clients
       ============================================================ */

    /**
     * Google AI Studio (Gemini) Client
     */
    private static function callGoogleGemini(string $apiKey, string $model, string $baseUrl, string $systemPrompt, string $userMessage, float $temp, int $maxTokens): array {
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Google AI Studio API key is missing. Please configure your key in Settings.'];
        }

        $base = !empty($baseUrl) ? $baseUrl : 'https://generativelanguage.googleapis.com/v1beta';
        $url = "{$base}/models/{$model}:generateContent?key=" . urlencode($apiKey);

        $payload = [
            'contents' => [
                [
                    'role' => 'user',
                    'parts' => [
                        ['text' => "System Instructions:\n" . $systemPrompt . "\n\nUser Question:\n" . $userMessage]
                    ]
                ]
            ],
            'generationConfig' => [
                'temperature' => $temp,
                'maxOutputTokens' => $maxTokens
            ]
        ];

        $response = self::httpPostJson($url, $payload, [
            'Content-Type: application/json'
        ]);

        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        if (isset($data['error'])) {
            return ['success' => false, 'error' => 'Gemini API Error: ' . ($data['error']['message'] ?? json_encode($data['error']))];
        }

        $text = $data['candidates'][0]['content']['parts'][0]['text'] ?? null;
        if (!$text) {
            return ['success' => false, 'error' => 'Gemini returned an empty response candidate.'];
        }

        return ['success' => true, 'text' => trim($text)];
    }

    /**
     * OpenRouter Client
     */
    private static function callOpenRouter(string $apiKey, string $model, string $baseUrl, string $systemPrompt, string $userMessage, float $temp, int $maxTokens): array {
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'OpenRouter API key is missing. Please configure your key in Settings.'];
        }

        $base = !empty($baseUrl) ? $baseUrl : 'https://openrouter.ai/api/v1';
        $url = "{$base}/chat/completions";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ],
            'temperature' => $temp,
            'max_tokens' => $maxTokens
        ];

        $headers = [
            'Authorization: Bearer ' . $apiKey,
            'Content-Type: application/json',
            'HTTP-Referer: http://localhost/YBT%20Management%20Software',
            'X-Title: YBT Management Software'
        ];

        $response = self::httpPostJson($url, $payload, $headers);
        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : $data['error'];
            return ['success' => false, 'error' => 'OpenRouter API Error: ' . $msg];
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!$text) {
            return ['success' => false, 'error' => 'OpenRouter returned an empty message choice.'];
        }

        return ['success' => true, 'text' => trim($text)];
    }

    /**
     * OpenAI & Custom OpenAI-Compatible Client
     */
    private static function callOpenAiCompatible(string $apiKey, string $model, string $baseUrl, string $systemPrompt, string $userMessage, float $temp, int $maxTokens): array {
        $base = !empty($baseUrl) ? $baseUrl : 'https://api.openai.com/v1';
        $url = "{$base}/chat/completions";

        $payload = [
            'model' => $model,
            'messages' => [
                ['role' => 'system', 'content' => $systemPrompt],
                ['role' => 'user', 'content' => $userMessage]
            ],
            'temperature' => $temp,
            'max_tokens' => $maxTokens
        ];

        $headers = ['Content-Type: application/json'];
        if (!empty($apiKey)) {
            $headers[] = 'Authorization: Bearer ' . $apiKey;
        }

        $response = self::httpPostJson($url, $payload, $headers);
        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        if (isset($data['error'])) {
            $msg = is_array($data['error']) ? ($data['error']['message'] ?? json_encode($data['error'])) : $data['error'];
            return ['success' => false, 'error' => 'API Error: ' . $msg];
        }

        $text = $data['choices'][0]['message']['content'] ?? null;
        if (!$text) {
            return ['success' => false, 'error' => 'Endpoint returned an empty choice.'];
        }

        return ['success' => true, 'text' => trim($text)];
    }

    /**
     * Anthropic Claude Client
     */
    private static function callAnthropic(string $apiKey, string $model, string $baseUrl, string $systemPrompt, string $userMessage, float $temp, int $maxTokens): array {
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Anthropic API key is missing. Please configure your key in Settings.'];
        }

        $base = !empty($baseUrl) ? $baseUrl : 'https://api.anthropic.com/v1';
        $url = "{$base}/messages";

        $payload = [
            'model' => $model,
            'system' => $systemPrompt,
            'messages' => [
                ['role' => 'user', 'content' => $userMessage]
            ],
            'max_tokens' => $maxTokens,
            'temperature' => $temp
        ];

        $headers = [
            'x-api-key: ' . $apiKey,
            'anthropic-version: 2023-06-01',
            'Content-Type: application/json'
        ];

        $response = self::httpPostJson($url, $payload, $headers);
        if (!$response['success']) {
            return $response;
        }

        $data = $response['data'];
        if (isset($data['error'])) {
            return ['success' => false, 'error' => 'Anthropic Error: ' . ($data['error']['message'] ?? json_encode($data['error']))];
        }

        $text = $data['content'][0]['text'] ?? null;
        if (!$text) {
            return ['success' => false, 'error' => 'Anthropic returned empty content.'];
        }

        return ['success' => true, 'text' => trim($text)];
    }

    /**
     * Generic JSON POST via cURL with SSL verification and timeout protection
     */
    private static function httpPostJson(string $url, array $payload, array $headers = []): array {
        $ch = curl_init($url);
        $jsonPayload = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $jsonPayload,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => false, // Compatible with local dev environments
            CURLOPT_SSL_VERIFYHOST => false
        ]);

        $rawResponse = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($rawResponse === false) {
            return ['success' => false, 'error' => 'Network request failed: ' . $curlError];
        }

        $decoded = json_decode($rawResponse, true);
        if ($decoded === null) {
            return ['success' => false, 'error' => "Invalid JSON from API (HTTP {$httpCode}): " . substr($rawResponse, 0, 200)];
        }

        if ($httpCode >= 400 && !isset($decoded['error'])) {
            return ['success' => false, 'error' => "HTTP error {$httpCode} from provider: " . substr($rawResponse, 0, 200)];
        }

        return ['success' => true, 'data' => $decoded, 'http_code' => $httpCode];
    }
}
