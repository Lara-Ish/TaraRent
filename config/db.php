<?php
/**
 * RentConnect Database Configuration & PDO Connection
 * PostgreSQL Database (Supabase)
 */

declare(strict_types=1);

if (date_default_timezone_get() !== 'Asia/Manila') {
    date_default_timezone_set('Asia/Manila');
}

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
require_once dirname(__DIR__) . '/includes/storage_service.php';

class Database {
    private static string $host = '';
    private static string $db   = '';
    private static string $user = '';
    private static string $pass = '';
    private static int    $port = 5432;
    private static string $sslmode = 'require';

    private static ?PDO $instance = null;
    private static bool $schemaChecked = false;

    /**
     * Parse connection string or environment overrides if provided
     */
    private static function initFromEnv(): void {
        loadEnvFile(dirname(__DIR__) . '/.env');
require_once dirname(__DIR__) . '/includes/storage_service.php';

        $connStr = getenv('DATABASE_URL') ?: (getenv('SUPABASE_DB_URL') ?: ($_ENV['DATABASE_URL'] ?? ($_ENV['SUPABASE_DB_URL'] ?? '')));
        if ($connStr) {
            $parsed = parse_url($connStr);
            if ($parsed) {
                if (isset($parsed['host'])) self::$host = $parsed['host'];
                if (isset($parsed['port'])) self::$port = (int)$parsed['port'];
                if (isset($parsed['user'])) self::$user = $parsed['user'];
                if (isset($parsed['pass'])) self::$pass = urldecode(trim($parsed['pass'], '[]'));
                if (isset($parsed['path'])) self::$db = ltrim($parsed['path'], '/');
            }
        } else {
            self::$host    = (string)(getenv('DB_HOST') ?: ($_ENV['DB_HOST'] ?? 'aws-1-ap-southeast-1.pooler.supabase.com'));
            self::$port    = (int)(getenv('DB_PORT') ?: ($_ENV['DB_PORT'] ?? 6543));
            self::$db      = (string)(getenv('DB_NAME') ?: ($_ENV['DB_NAME'] ?? 'postgres'));
            self::$user    = (string)(getenv('DB_USER') ?: ($_ENV['DB_USER'] ?? 'postgres.asqnfbhzgqdlqxnuuugu'));
            self::$pass    = (string)(getenv('DB_PASSWORD') ?: ($_ENV['DB_PASSWORD'] ?? 'TaraRent2026'));
            self::$sslmode = (string)(getenv('DB_SSLMODE') ?: ($_ENV['DB_SSLMODE'] ?? 'require'));
        }
    }
    /**
     * Self-healing schema validation: ensures verification columns exist in users table
     * and payments table has payment_type and unpaid status (PostgreSQL syntax)
     */
    private static function ensureVerificationColumns(PDO $pdo): void {
        if (self::$schemaChecked) {
            return;
        }
        self::$schemaChecked = true;
        try {
            // PostgreSQL ALTER TABLE ADD COLUMN IF NOT EXISTS without MySQL-specific AFTER keyword
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS live_photo_filename VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS face_descriptor TEXT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_id_path VARCHAR(255) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS pending_id_type VARCHAR(50) NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_notes TEXT NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS terms_accepted BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS privacy_acknowledged BOOLEAN NOT NULL DEFAULT FALSE");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS rental_status VARCHAR(20) NOT NULL DEFAULT 'active'");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS move_out_date DATE NULL");
            $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS monthly_rent DECIMAL(10,2) NULL");
            $pdo->exec("ALTER TABLE payments ADD COLUMN IF NOT EXISTS payment_type VARCHAR(50) NOT NULL DEFAULT 'Rent'");

            // Index creation if not exists
            try {
                $pdo->exec("CREATE INDEX IF NOT EXISTS idx_users_email ON users (email)");
            } catch (Throwable $e) {}
        } catch (Throwable $e) {
            // Silently ignore if already present or user lacks DDL permissions
        }
    }

    /**
     * Get singleton PDO PostgreSQL connection instance with multi-pooler resilience
     */
    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::initFromEnv();

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
            PDO::ATTR_TIMEOUT            => 5,
        ];

        // Build list of candidate endpoints (configured primary + alternate pooler/port fallbacks)
        $candidates = [
            ['host' => self::$host, 'port' => self::$port],
        ];

        // If port 6543 (transaction mode), try port 5432 (session mode)
        if (self::$port === 6543) {
            $candidates[] = ['host' => self::$host, 'port' => 5432];
        } elseif (self::$port === 5432) {
            $candidates[] = ['host' => self::$host, 'port' => 6543];
        }

        // Alternate region pooler node fallback (aws-1 <-> aws-0)
        if (str_contains(self::$host, 'aws-1-')) {
            $altHost = str_replace('aws-1-', 'aws-0-', self::$host);
            $candidates[] = ['host' => $altHost, 'port' => self::$port];
            $candidates[] = ['host' => $altHost, 'port' => (self::$port === 6543 ? 5432 : 6543)];
        } elseif (str_contains(self::$host, 'aws-0-')) {
            $altHost = str_replace('aws-0-', 'aws-1-', self::$host);
            $candidates[] = ['host' => $altHost, 'port' => self::$port];
            $candidates[] = ['host' => $altHost, 'port' => (self::$port === 6543 ? 5432 : 6543)];
        }

        $lastException = null;

        foreach ($candidates as $cand) {
            $dsn = sprintf(
                'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
                $cand['host'],
                $cand['port'],
                self::$db,
                self::$sslmode
            );

            try {
                self::$instance = new PDO($dsn, self::$user, self::$pass, $options);
                self::ensureVerificationColumns(self::$instance);
                return self::$instance;
            } catch (PDOException $e) {
                $lastException = $e;
                continue;
            }
        }

        // If all attempts failed
        http_response_code(500);

        // Check if request is an API / AJAX call
        $isApi = (!empty($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json')) ||
                 (!empty($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower($_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest') ||
                 (isset($_SERVER['REQUEST_URI']) && str_contains($_SERVER['REQUEST_URI'], '/api/'));

        if ($isApi) {
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status'  => 'error',
                'message' => 'Database connection failed: ' . ($lastException ? $lastException->getMessage() : 'Unable to connect to Supabase database.'),
                'hint'    => 'If using Supabase free tier, check if project is paused in the Supabase Dashboard (https://supabase.com/dashboard) and click "Resume Project".'
            ], JSON_PRETTY_PRINT);
            exit;
        }

        // For browser visits, render a clean, human-readable recovery screen
        header('Content-Type: text/html; charset=UTF-8');
        $errorMsg = htmlspecialchars($lastException ? $lastException->getMessage() : 'Connection refused / offline', ENT_QUOTES, 'UTF-8');
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <title>Database Offline - TaraRent</title>
            <style>
                body {
                    margin: 0;
                    padding: 0;
                    min-height: 100vh;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    background: #0f172a;
                    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
                    color: #f8fafc;
                }
                .card {
                    max-width: 580px;
                    margin: 20px;
                    background: #1e293b;
                    border: 1px solid #334155;
                    border-radius: 16px;
                    padding: 32px;
                    box-shadow: 0 20px 25px -5px rgba(0, 0, 0, 0.5), 0 8px 10px -6px rgba(0, 0, 0, 0.5);
                }
                .icon {
                    width: 56px;
                    height: 56px;
                    background: rgba(239, 68, 68, 0.15);
                    border: 1px solid rgba(239, 68, 68, 0.3);
                    border-radius: 12px;
                    display: flex;
                    align-items: center;
                    justify-content: center;
                    font-size: 28px;
                    margin-bottom: 20px;
                }
                h1 {
                    font-size: 22px;
                    font-weight: 700;
                    margin: 0 0 10px 0;
                    color: #ffffff;
                }
                p {
                    font-size: 14px;
                    line-height: 1.6;
                    color: #94a3b8;
                    margin: 0 0 16px 0;
                }
                .steps {
                    background: #0f172a;
                    border: 1px solid #334155;
                    border-radius: 10px;
                    padding: 16px;
                    margin: 20px 0;
                }
                .steps ol {
                    margin: 0;
                    padding-left: 20px;
                    color: #cbd5e1;
                    font-size: 13.5px;
                    line-height: 1.8;
                }
                .steps strong {
                    color: #38bdf8;
                }
                .btn {
                    display: inline-block;
                    background: #0284c7;
                    color: #ffffff;
                    text-decoration: none;
                    padding: 12px 24px;
                    border-radius: 8px;
                    font-size: 14px;
                    font-weight: 600;
                    transition: background 0.2s ease;
                    margin-right: 10px;
                }
                .btn:hover {
                    background: #0369a1;
                }
                .btn-secondary {
                    background: #334155;
                    color: #f8fafc;
                }
                .btn-secondary:hover {
                    background: #475569;
                }
                .err-detail {
                    margin-top: 24px;
                    font-size: 11px;
                    color: #64748b;
                    word-break: break-all;
                    font-family: monospace;
                    background: #0a0f1d;
                    padding: 10px;
                    border-radius: 6px;
                }
            </style>
        </head>
        <body>
            <div class="card">
                <div class="icon">🔌</div>
                <h1>Database Connection Offline</h1>
                <p>TaraRent could not establish a connection to your Supabase PostgreSQL database.</p>
                
                <div class="steps">
                    <strong style="display:block; margin-bottom:8px; color:#f8fafc;">How to Restore Connectivity:</strong>
                    <ol>
                        <li>Log into your <strong><a href="https://supabase.com/dashboard" target="_blank" style="color:#38bdf8; text-decoration:underline;">Supabase Dashboard</a></strong>.</li>
                        <li>Select your project: <strong>TaraRent</strong> (ID: <code>asqnfbhzgqdlqxnuuugu</code>).</li>
                        <li>If the project shows <strong>"Paused"</strong>, click the <strong>"Resume Project"</strong> / <strong>"Restore"</strong> button.</li>
                        <li>Wait 1–2 minutes for the database container to finish waking up.</li>
                    </ol>
                </div>

                <div style="margin-top: 24px;">
                    <a href="https://supabase.com/dashboard" target="_blank" class="btn">Open Supabase Dashboard</a>
                    <a href="javascript:location.reload()" class="btn btn-secondary">Retry Connection</a>
                </div>

                <div class="err-detail">
                    <strong>Technical Error:</strong> <?= $errorMsg ?>
                </div>
            </div>
        </body>
        </html>
        <?php
        exit;
    }
}
