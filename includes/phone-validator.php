<?php
declare(strict_types=1);

/**
 * DJ CRM Software — International Country Phone Validator & Metadata
 * Provides country-specific rules, lengths, patterns, and strict Pakistani 11-digit validation.
 */
class PhoneValidator {
    /**
     * Complete metadata list of supported countries
     */
    public static function getCountries(): array {
        return [
            'PK' => [
                'name' => 'Pakistan',
                'flag' => '🇵🇰',
                'code' => '+92',
                'dial_code' => '92',
                'min_len' => 11,
                'max_len' => 11,
                'pattern' => '^03[0-9]{9}$',
                'placeholder' => '03001234567',
                'hint' => 'Must be exactly 11 digits starting with 03 (e.g. 03001234567)'
            ],
            'AE' => [
                'name' => 'United Arab Emirates',
                'flag' => '🇦🇪',
                'code' => '+971',
                'dial_code' => '971',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^5[0-9]{8}$',
                'placeholder' => '501234567',
                'hint' => '9 digits starting with 5 (e.g. 501234567)'
            ],
            'SA' => [
                'name' => 'Saudi Arabia',
                'flag' => '🇸🇦',
                'code' => '+966',
                'dial_code' => '966',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^5[0-9]{8}$',
                'placeholder' => '501234567',
                'hint' => '9 digits starting with 5 (e.g. 501234567)'
            ],
            'QA' => [
                'name' => 'Qatar',
                'flag' => '🇶🇦',
                'code' => '+974',
                'dial_code' => '974',
                'min_len' => 8,
                'max_len' => 8,
                'pattern' => '^[3567][0-9]{7}$',
                'placeholder' => '33123456',
                'hint' => '8 digits (e.g. 33123456)'
            ],
            'OM' => [
                'name' => 'Oman',
                'flag' => '🇴🇲',
                'code' => '+968',
                'dial_code' => '968',
                'min_len' => 8,
                'max_len' => 8,
                'pattern' => '^[79][0-9]{7}$',
                'placeholder' => '91234567',
                'hint' => '8 digits starting with 7 or 9'
            ],
            'KW' => [
                'name' => 'Kuwait',
                'flag' => '🇰🇼',
                'code' => '+965',
                'dial_code' => '965',
                'min_len' => 8,
                'max_len' => 8,
                'pattern' => '^[569][0-9]{7}$',
                'placeholder' => '91234567',
                'hint' => '8 digits starting with 5, 6, or 9'
            ],
            'BH' => [
                'name' => 'Bahrain',
                'flag' => '🇧🇭',
                'code' => '+973',
                'dial_code' => '973',
                'min_len' => 8,
                'max_len' => 8,
                'pattern' => '^3[0-9]{7}$',
                'placeholder' => '39123456',
                'hint' => '8 digits starting with 3'
            ],
            'GB' => [
                'name' => 'United Kingdom',
                'flag' => '🇬🇧',
                'code' => '+44',
                'dial_code' => '44',
                'min_len' => 10,
                'max_len' => 11,
                'pattern' => '^[0-9]{10,11}$',
                'placeholder' => '7123456789',
                'hint' => '10 or 11 digits'
            ],
            'US' => [
                'name' => 'United States',
                'flag' => '🇺🇸',
                'code' => '+1',
                'dial_code' => '1',
                'min_len' => 10,
                'max_len' => 10,
                'pattern' => '^[2-9][0-9]{9}$',
                'placeholder' => '2025550143',
                'hint' => '10 digits standard'
            ],
            'CA' => [
                'name' => 'Canada',
                'flag' => '🇨🇦',
                'code' => '+1',
                'dial_code' => '1',
                'min_len' => 10,
                'max_len' => 10,
                'pattern' => '^[2-9][0-9]{9}$',
                'placeholder' => '4165550143',
                'hint' => '10 digits standard'
            ],
            'IN' => [
                'name' => 'India',
                'flag' => '🇮🇳',
                'code' => '+91',
                'dial_code' => '91',
                'min_len' => 10,
                'max_len' => 10,
                'pattern' => '^[6-9][0-9]{9}$',
                'placeholder' => '9876543210',
                'hint' => '10 digits starting with 6-9'
            ],
            'BD' => [
                'name' => 'Bangladesh',
                'flag' => '🇧🇩',
                'code' => '+880',
                'dial_code' => '880',
                'min_len' => 10,
                'max_len' => 10,
                'pattern' => '^1[3-9][0-9]{8}$',
                'placeholder' => '1712345678',
                'hint' => '10 digits starting with 1'
            ],
            'AF' => [
                'name' => 'Afghanistan',
                'flag' => '🇦🇫',
                'code' => '+93',
                'dial_code' => '93',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^7[0-9]{8}$',
                'placeholder' => '701234567',
                'hint' => '9 digits starting with 7'
            ],
            'TR' => [
                'name' => 'Turkey',
                'flag' => '🇹🇷',
                'code' => '+90',
                'dial_code' => '90',
                'min_len' => 10,
                'max_len' => 10,
                'pattern' => '^5[0-9]{9}$',
                'placeholder' => '5012345678',
                'hint' => '10 digits starting with 5'
            ],
            'CN' => [
                'name' => 'China',
                'flag' => '🇨🇳',
                'code' => '+86',
                'dial_code' => '86',
                'min_len' => 11,
                'max_len' => 11,
                'pattern' => '^1[3-9][0-9]{9}$',
                'placeholder' => '13812345678',
                'hint' => '11 digits starting with 1'
            ],
            'MY' => [
                'name' => 'Malaysia',
                'flag' => '🇲🇾',
                'code' => '+60',
                'dial_code' => '60',
                'min_len' => 9,
                'max_len' => 10,
                'pattern' => '^1[0-9]{8,9}$',
                'placeholder' => '123456789',
                'hint' => '9 to 10 digits'
            ],
            'SG' => [
                'name' => 'Singapore',
                'flag' => '🇸🇬',
                'code' => '+65',
                'dial_code' => '65',
                'min_len' => 8,
                'max_len' => 8,
                'pattern' => '^[89][0-9]{7}$',
                'placeholder' => '81234567',
                'hint' => '8 digits starting with 8 or 9'
            ],
            'AU' => [
                'name' => 'Australia',
                'flag' => '🇦🇺',
                'code' => '+61',
                'dial_code' => '61',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^4[0-9]{8}$',
                'placeholder' => '412345678',
                'hint' => '9 digits starting with 4'
            ],
            'DE' => [
                'name' => 'Germany',
                'flag' => '🇩🇪',
                'code' => '+49',
                'dial_code' => '49',
                'min_len' => 10,
                'max_len' => 11,
                'pattern' => '^[0-9]{10,11}$',
                'placeholder' => '15112345678',
                'hint' => '10 to 11 digits'
            ],
            'FR' => [
                'name' => 'France',
                'flag' => '🇫🇷',
                'code' => '+33',
                'dial_code' => '33',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^[67][0-9]{8}$',
                'placeholder' => '612345678',
                'hint' => '9 digits starting with 6 or 7'
            ],
            'ZA' => [
                'name' => 'South Africa',
                'flag' => '🇿🇦',
                'code' => '+27',
                'dial_code' => '27',
                'min_len' => 9,
                'max_len' => 9,
                'pattern' => '^[6-8][0-9]{8}$',
                'placeholder' => '712345678',
                'hint' => '9 digits'
            ],
            'OTHER' => [
                'name' => 'Other International',
                'flag' => '🌐',
                'code' => '+',
                'dial_code' => '',
                'min_len' => 7,
                'max_len' => 15,
                'pattern' => '^[0-9]{7,15}$',
                'placeholder' => 'Phone number',
                'hint' => '7 to 15 digits'
            ]
        ];
    }

    /**
     * Validate phone number according to country rules
     * Returns: ['valid' => bool, 'error' => ?string, 'formatted' => string]
     */
    public static function validate(string $countryCodeOrKey, string $rawNumber): array {
        $rawNumber = trim($rawNumber);
        if ($rawNumber === '') {
            return ['valid' => false, 'error' => 'Phone number cannot be empty.', 'formatted' => ''];
        }

        $countries = self::getCountries();

        // Match country key or calling code
        $rule = null;
        $matchedKey = 'PK';
        $countryCodeOrKey = trim($countryCodeOrKey);

        if (isset($countries[$countryCodeOrKey])) {
            $rule = $countries[$countryCodeOrKey];
            $matchedKey = $countryCodeOrKey;
        } else {
            foreach ($countries as $k => $c) {
                if ($c['code'] === $countryCodeOrKey || $c['dial_code'] === ltrim($countryCodeOrKey, '+')) {
                    $rule = $c;
                    $matchedKey = $k;
                    break;
                }
            }
        }

        if (!$rule) {
            $rule = $countries['OTHER'];
            $matchedKey = 'OTHER';
        }

        // Clean digits
        $clean = preg_replace('/[^0-9]/', '', $rawNumber);

        // Strict Pakistan (+92) validation
        if ($matchedKey === 'PK' || $rule['code'] === '+92' || ($rule['dial_code'] ?? '') === '92') {
            // Normalize international dial code 923001234567 -> 03001234567
            if (strlen($clean) === 12 && substr($clean, 0, 2) === '92') {
                $clean = '0' . substr($clean, 2);
            }
            // Pakistan national mobile input must be exactly 11 digits starting with 03 (or 10 digits starting with 3)
            $len = strlen($clean);
            if ($len > 11) {
                return [
                    'valid' => false,
                    'error' => "Pakistani mobile number cannot exceed 11 digits (entered {$len} digits).",
                    'formatted' => $rawNumber
                ];
            }
            if ($len < 10) {
                return [
                    'valid' => false,
                    'error' => "Pakistani mobile number is incomplete (entered {$len} digits, 11 required).",
                    'formatted' => $rawNumber
                ];
            }
            if ($len === 11) {
                if (substr($clean, 0, 2) !== '03') {
                    return [
                        'valid' => false,
                        'error' => "Pakistani mobile number must start with 03 (e.g. 03001234567).",
                        'formatted' => $rawNumber
                    ];
                }
            } elseif ($len === 10) {
                if (substr($clean, 0, 1) !== '3') {
                    return [
                        'valid' => false,
                        'error' => "Pakistani mobile number must start with 03 (e.g. 03001234567).",
                        'formatted' => $rawNumber
                    ];
                }
                // Prepend leading 0 to make it standard 11 digits
                $clean = '0' . $clean;
            }

            return [
                'valid' => true,
                'error' => null,
                'formatted' => '+92 ' . substr($clean, 1) // Store international: +92 3001234567
            ];
        }

        // International validation
        $len = strlen($clean);
        if ($len < $rule['min_len']) {
            return [
                'valid' => false,
                'error' => "Phone number is too short for {$rule['name']} (min. {$rule['min_len']} digits).",
                'formatted' => $rawNumber
            ];
        }
        if ($len > $rule['max_len']) {
            return [
                'valid' => false,
                'error' => "Phone number exceeds maximum length of {$rule['max_len']} digits for {$rule['name']}.",
                'formatted' => $rawNumber
            ];
        }

        if (!empty($rule['pattern']) && !preg_match('/' . $rule['pattern'] . '/', $clean)) {
            return [
                'valid' => false,
                'error' => "Invalid phone number format for {$rule['name']}. Hint: {$rule['hint']}",
                'formatted' => $rawNumber
            ];
        }

        return [
            'valid' => true,
            'error' => null,
            'formatted' => $rule['code'] . ' ' . $clean
        ];
    }

    public static function getCountriesJson(): string {
        return json_encode(self::getCountries(), JSON_UNESCAPED_UNICODE);
    }
}
