<?php
/**
 * TaraRent - Supabase Cloud Storage Service
 * Handles uploading binary assets directly to Supabase Storage Buckets via REST API.
 */

declare(strict_types=1);

// Ensure environment variables are loaded
if (!function_exists('loadEnvFile') && file_exists(dirname(__DIR__) . '/config/credentials.php')) {
    require_once dirname(__DIR__) . '/config/credentials.php';
} elseif (function_exists('loadEnvFile') && empty(getenv('SUPABASE_URL')) && empty($_ENV['SUPABASE_URL'])) {
    loadEnvFile(dirname(__DIR__) . '/.env');
}

if (!function_exists('uploadToSupabaseStorage')) {
    /**
     * Upload a file directly to Supabase Storage via REST API.
     *
     * @param string $fileTmpPath Absolute local path to temporary file (e.g. $_FILES['...']['tmp_name'])
     * @param string $fileName Target filename or path in the bucket (e.g. 'receipt_123.jpg')
     * @param string $bucketName Supabase Storage bucket name (e.g. 'receipts', 'maintenance', 'reports', 'avatars')
     * @return string|false Public URL of uploaded file on success, or false on failure
     */
    function uploadToSupabaseStorage(string $fileTmpPath, string $fileName, string $bucketName = 'tararent'): string|false
    {
        // 1. Resolve configuration from environment
        $supabaseUrl = getenv('SUPABASE_URL') ?: ($_ENV['SUPABASE_URL'] ?? ($_SERVER['SUPABASE_URL'] ?? ''));
        if (empty($supabaseUrl)) {
            $supabaseUrl = 'https://asqnfbhzgqdlqxnuuugu.supabase.co';
        }
        $supabaseUrl = rtrim($supabaseUrl, '/');

        $supabaseServiceKey = getenv('SUPABASE_SERVICE_KEY') ?: ($_ENV['SUPABASE_SERVICE_KEY'] ?? ($_SERVER['SUPABASE_SERVICE_KEY'] ?? ''));
        $cleanFileName = ltrim($fileName, '/');
        $cleanBucket = trim($bucketName);

        // Public URL that will be returned upon successful upload
        $publicUrl = "{$supabaseUrl}/storage/v1/object/public/{$cleanBucket}/{$cleanFileName}";

        // 2. Validate input temporary file
        if (!file_exists($fileTmpPath) || !is_readable($fileTmpPath)) {
            error_log("[Supabase Storage] File does not exist or is unreadable: {$fileTmpPath}");
            return false;
        }

        $fileData = file_get_contents($fileTmpPath);
        if ($fileData === false) {
            error_log("[Supabase Storage] Failed to read temporary file content: {$fileTmpPath}");
            return false;
        }

        // 3. Detect MIME type
        $mimeType = 'application/octet-stream';
        if (function_exists('finfo_open')) {
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            if ($finfo) {
                $detected = finfo_file($finfo, $fileTmpPath);
                if (!empty($detected)) {
                    $mimeType = $detected;
                }
                finfo_close($finfo);
            }
        } elseif (function_exists('mime_content_type')) {
            $detected = @mime_content_type($fileTmpPath);
            if (!empty($detected)) {
                $mimeType = $detected;
            }
        }

        // Fallback MIME mapping by extension if needed
        if ($mimeType === 'application/octet-stream') {
            $ext = strtolower(pathinfo($cleanFileName, PATHINFO_EXTENSION));
            $extMimes = [
                'jpg'  => 'image/jpeg',
                'jpeg' => 'image/jpeg',
                'png'  => 'image/png',
                'webp' => 'image/webp',
                'gif'  => 'image/gif',
                'pdf'  => 'application/pdf',
                'doc'  => 'application/msword',
                'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            ];
            if (isset($extMimes[$ext])) {
                $mimeType = $extMimes[$ext];
            }
        }

        // 4. Construct Supabase Storage REST API Endpoint
        // Endpoint: $SUPABASE_URL/storage/v1/object/$bucketName/$fileName
        $endpoint = "{$supabaseUrl}/storage/v1/object/{$cleanBucket}/{$cleanFileName}";

        // 5. Send POST request via PHP cURL
        $ch = curl_init($endpoint);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => $fileData,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $supabaseServiceKey,
                'apiKey: ' . $supabaseServiceKey,
                'Content-Type: ' . $mimeType,
                'x-upsert: true',
                'User-Agent: TaraRent/1.0'
            ]
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        // 6. Check for success (HTTP 200 OK or HTTP 201 Created)
        if ($httpCode >= 200 && $httpCode < 300) {
            error_log("[Supabase Storage] Successfully uploaded '{$cleanFileName}' to bucket '{$cleanBucket}'. Public URL: {$publicUrl}");
            return $publicUrl;
        }

        error_log("[Supabase Storage] Upload failed (HTTP {$httpCode}): {$response} | cURL error: {$curlError} | Endpoint: {$endpoint}");

        // If service key is placeholder or invalid in dev/local environment, log a specific warning
        if (str_contains((string)$supabaseServiceKey, 'your_supabase') || empty($supabaseServiceKey)) {
            error_log("[Supabase Storage Warning] SUPABASE_SERVICE_KEY is currently a placeholder. Please configure your actual Supabase Service Role Key in .env or Render environment variables.");
        }

        return false;
    }
}
