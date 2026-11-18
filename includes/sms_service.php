<?php
/**
 * TaraRent - iProg SMS Gateway Service
 * Handles SMS notifications via the iProg SMS API using isolated cURL instances.
 * Securely loads credentials from environment variables or .env file.
 */

declare(strict_types=1);

if (!function_exists('loadEnvFile')) {
    function loadEnvFile(string $filePath): void
    {
        if (!file_exists($filePath)) {
            return;
        }

        $lines = @file($filePath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#')) {
                continue;
            }

            $parts = explode('=', $line, 2);
            if (count($parts) === 2) {
                $key = trim($parts[0]);
                $val = trim($parts[1]);

                if ((str_starts_with($val, '"') && str_ends_with($val, '"')) ||
                    (str_starts_with($val, "'") && str_ends_with($val, "'"))) {
                    $val = substr($val, 1, -1);
                }

                $currentVal = getenv($key);
                if ($currentVal === false || $currentVal === '') {
                    $_ENV[$key] = $val;
                    $_SERVER[$key] = $val;
                    putenv("{$key}={$val}");
                }
            }
        }
    }
}

/**
 * Sanitizes and normalizes Philippine mobile phone numbers into standard international format (639XXXXXXXXX).
 *
 * Strips all spaces, dashes, parentheses, +, and non-numeric characters.
 * If the number starts with '09', strictly replaces the leading '0' with '63' (e.g., 09171234567 -> 639171234567).
 * If the number starts with '9' (10 digits), prepends '63' (e.g., 9171234567 -> 639171234567).
 * If the number starts with '+63' or '63', strips '+' and ensures standard '639XXXXXXXXX'.
 *
 * @param string $phone Raw phone number
 * @return string Sanitized 11-13 digit number starting with 639, or empty string if invalid.
 */
function sanitizeIprogPhoneNumber(string $phone): string
{
    // Strip all non-digit characters (spaces, dashes, parentheses, dots, +, etc.)
    $cleaned = preg_replace('/[^0-9]/', '', trim($phone));

    if ($cleaned === '') {
        return '';
    }

    // Handle common typo: 6309XXXXXXXXX (13 digits: +63 with redundant 0)
    if (str_starts_with($cleaned, '6309') && strlen($cleaned) === 13) {
        $cleaned = '639' . substr($cleaned, 4);
    }
    // If the number starts with '09', replace '0' with '63' (Philippine country code format)
    elseif (str_starts_with($cleaned, '09')) {
        $cleaned = '63' . substr($cleaned, 1);
    }
    // If number starts with '9' and has 10 digits (e.g. 9171234567), prepend '63'
    elseif (str_starts_with($cleaned, '9') && strlen($cleaned) === 10) {
        $cleaned = '63' . $cleaned;
    }
    // Handle international dialing prefix 00639
    elseif (str_starts_with($cleaned, '00639')) {
        $cleaned = substr($cleaned, 2);
    }

    // Validate: must start with 639 and have reasonable Philippine mobile digit length (11 to 13 digits)
    if (str_starts_with($cleaned, '639') && strlen($cleaned) >= 11 && strlen($cleaned) <= 13) {
        return $cleaned;
    }

    return '';
}

/**
 * Sends an SMS message via the iProg SMS API.
 *
 * Features:
 * - Strict Philippine country code enforcement (639XXXXXXXXX)
 * - Isolated cURL lifecycle (curl_init and curl_close on every request) to prevent state/header bleed
 * - Captures exact JSON response from the iProg server
 * - Diagnostic output (logs and echoes exact JSON error response if cURL or API fails)
 *
 * @param string $phoneNumber Target recipient phone number
 * @param string $message Text message content
 * @return array Result array [success => bool, message => string, http_code => int, response => ?string, raw_response => ?string, data => ?array]
 */
function sendIprogSMS(string $phoneNumber, string $message): array
{
    loadEnvFile(dirname(__DIR__) . '/.env');

    $apiToken = (string)(getenv('IPROG_SMS_TOKEN') 
        ?: ($_ENV['IPROG_SMS_TOKEN'] 
        ?? ($_SERVER['IPROG_SMS_TOKEN'] 
        ?? 'da067e5fd036dc4794f9f6d1e62f0c87de1b5350')));

    $apiUrl = (string)(getenv('IPROG_SMS_API_URL') 
        ?: ($_ENV['IPROG_SMS_API_URL'] 
        ?? ($_SERVER['IPROG_SMS_API_URL'] 
        ?? 'https://sms.iprogtech.com/api/v1/sms_messages')));

    // 1. Phone number sanitization with strict country code enforcement
    $formattedPhone = sanitizeIprogPhoneNumber($phoneNumber);

    if ($formattedPhone === '') {
        $errMsg = "Invalid or unparseable phone number provided: '{$phoneNumber}'. Must be a valid Philippine mobile number (e.g., 09XXXXXXXXX or 639XXXXXXXXX).";
        $diagMsg = "[iProg SMS Error] Target: '{$phoneNumber}' | Validation Failure: {$errMsg}";
        error_log($diagMsg);
        if (php_sapi_name() === 'cli') {
            echo $diagMsg . PHP_EOL;
        }
        return [
            'success'      => false,
            'message'      => $errMsg,
            'http_code'    => 422,
            'response'     => null,
            'raw_response' => null,
            'data'         => null
        ];
    }

    if (trim($message) === '') {
        $errMsg = "SMS message content cannot be empty.";
        $diagMsg = "[iProg SMS Error] Target: '{$formattedPhone}' | Validation Failure: {$errMsg}";
        error_log($diagMsg);
        if (php_sapi_name() === 'cli') {
            echo $diagMsg . PHP_EOL;
        }
        return [
            'success'      => false,
            'message'      => $errMsg,
            'http_code'    => 422,
            'response'     => null,
            'raw_response' => null,
            'data'         => null
        ];
    }

    $payload = [
        'api_token'    => $apiToken,
        'phone_number' => $formattedPhone,
        'message'      => $message
    ];

    // 2. Isolated cURL handle initialization & execution
    $ch = curl_init();
    $response = false;
    $httpCode = 0;
    $curlError = '';

    try {
        curl_setopt_array($ch, [
            CURLOPT_URL            => $apiUrl,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_SLASHES),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Bearer ' . $apiToken,
                'User-Agent: TaraRent/1.0'
            ],
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $response = curl_exec($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
    } finally {
        // Ensure cURL handle is strictly closed after execution to prevent state/header bleed
        if (is_resource($ch) || (is_object($ch) && $ch instanceof CurlHandle)) {
            curl_close($ch);
        }
    }

    // 3. Handle cURL transport errors
    if ($curlError !== '') {
        $errMsg = "cURL communication error: {$curlError}";
        $diagMsg = "[iProg SMS Error] Target: '{$formattedPhone}' | Transport Failure: {$errMsg} (HTTP {$httpCode})";
        error_log($diagMsg);
        if (php_sapi_name() === 'cli') {
            echo $diagMsg . PHP_EOL;
        }
        return [
            'success'      => false,
            'message'      => $errMsg,
            'http_code'    => $httpCode ?: 500,
            'response'     => null,
            'raw_response' => null,
            'data'         => null
        ];
    }

    // 4. Capture & decode JSON response
    $rawResponse = is_string($response) ? trim($response) : '';
    $decoded = json_decode($rawResponse, true);
    $apiStatus = $decoded['status'] ?? null;
    $rawMsg = $decoded['message'] ?? '';
    $apiMessage = is_array($rawMsg) ? implode('; ', $rawMsg) : (string)$rawMsg;

    // Both HTTP status 200 and API payload status 200/success indicate success
    $isSuccess = ($httpCode >= 200 && $httpCode < 300) 
                 && ($apiStatus === 200 || $apiStatus === '200' || $apiStatus === 'success');

    // If HTTP status is not 200 or API returns error, log and echo exact JSON response
    if (!$isSuccess) {
        $failureReason = $apiMessage ?: ("HTTP {$httpCode}: " . ($rawResponse ?: 'Empty server response'));
        $diagMsg = "[iProg SMS Diagnostic] Send failure to '{$formattedPhone}'. HTTP: {$httpCode}. Exact Server Response: " . ($rawResponse !== '' ? $rawResponse : 'EMPTY_RESPONSE');
        
        // Log exact JSON response for debugging
        error_log($diagMsg);

        // Echo exact JSON response in CLI or diagnostic modes
        if (php_sapi_name() === 'cli') {
            echo $diagMsg . PHP_EOL;
        }

        return [
            'success'      => false,
            'message'      => $failureReason,
            'http_code'    => $httpCode,
            'response'     => $rawResponse,
            'raw_response' => $rawResponse,
            'data'         => $decoded
        ];
    }

    return [
        'success'      => true,
        'message'      => $apiMessage ?: 'SMS successfully queued for delivery.',
        'message_id'   => $decoded['message_id'] ?? null,
        'http_code'    => $httpCode,
        'response'     => $rawResponse,
        'raw_response' => $rawResponse,
        'data'         => $decoded
    ];
}

class SmsService
{
    /**
     * Sanitizes and normalizes phone numbers for iProg SMS.
     */
    public static function formatPhoneNumber(string $phone): string
    {
        return sanitizeIprogPhoneNumber($phone);
    }

    /**
     * Sends an SMS message to the specified phone number via the iProg SMS API.
     */
    public static function send(string $phoneNumber, string $message): array
    {
        return sendIprogSMS($phoneNumber, $message);
    }
}

if (!function_exists('sendSMS')) {
    function sendSMS(string $phoneNumber, string $message): bool
    {
        $result = sendIprogSMS($phoneNumber, $message);
        return (bool)($result['success'] ?? false);
    }
}
