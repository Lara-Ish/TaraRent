<?php
/**
 * RentConnect - Verification Credentials & Configuration
 * Includes Face++ Biometric API and Dedicated OCR Configurations
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

loadEnvFile(dirname(__DIR__) . '/.env');

// ----------------------------------------------------------------------------
// 1. Face++ API Credentials (Loaded from Environment Variables)
// ----------------------------------------------------------------------------
define('FACEPP_API_KEY', (string)(getenv('FACEPP_API_KEY') ?: ($_ENV['FACEPP_API_KEY'] ?? '')));
define('FACEPP_API_SECRET', (string)(getenv('FACEPP_API_SECRET') ?: ($_ENV['FACEPP_API_SECRET'] ?? '')));
define('FACEPP_COMPARE_URL', (string)(getenv('FACEPP_COMPARE_URL') ?: ($_ENV['FACEPP_COMPARE_URL'] ?? 'https://api-us.faceplusplus.com/facepp/v3/compare')));

// ----------------------------------------------------------------------------
// 2. Optical Character Recognition (OCR) Configuration
// ----------------------------------------------------------------------------
if (!defined('OCR_ENGINE')) {
    define('OCR_ENGINE', (string)(getenv('OCR_ENGINE') ?: ($_ENV['OCR_ENGINE'] ?? 'tesseract')));
}

if (!defined('TESSERACT_PATH')) {
    define('TESSERACT_PATH', (string)(getenv('TESSERACT_PATH') ?: ($_ENV['TESSERACT_PATH'] ?? '')));
}

// Legacy constants safely defined for backward compatibility
if (!defined('GEMINI_API_KEY')) {
    define('GEMINI_API_KEY', '');
}
if (!defined('GEMINI_API_MODEL')) {
    define('GEMINI_API_MODEL', 'gemini-2.5-flash');
}
if (!defined('GEMINI_API_URL')) {
    define('GEMINI_API_URL', 'https://generativelanguage.googleapis.com/v1beta/models/' . GEMINI_API_MODEL . ':generateContent');
}

// ----------------------------------------------------------------------------
// 3. Supabase Cloud Storage API Configuration
// ----------------------------------------------------------------------------
if (!defined('SUPABASE_URL')) {
    define('SUPABASE_URL', (string)(getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? 'https://asqnfbhzgqdlqxnuuugu.supabase.co')));
}
if (!defined('SUPABASE_SERVICE_KEY')) {
    define('SUPABASE_SERVICE_KEY', (string)(getenv('SUPABASE_SERVICE_KEY') ?: ($_ENV['SUPABASE_SERVICE_KEY'] ?? '')));
}

require_once dirname(__DIR__) . '/includes/storage_service.php';
