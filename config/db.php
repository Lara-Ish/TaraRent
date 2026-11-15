<?php
/**
 * RentConnect Database Configuration & PDO Connection
 * PostgreSQL Database (Supabase)
 */

declare(strict_types=1);

class Database {
    // Supabase PostgreSQL Database Credentials
    private static string $host = 'aws-1-ap-southeast-1.pooler.supabase.com';
    private static string $db   = 'postgres';
    private static string $user = 'postgres.asqnfbhzgqdlqxnuuugu';
    private static string $pass = 'TaraRent2026';
    private static int    $port = 6543;
    private static string $sslmode = 'require';

    private static ?PDO $instance = null;
    private static bool $schemaChecked = false;

    /**
     * Parse connection string or environment overrides if provided
     */
    private static function initFromEnv(): void {
        $connStr = getenv('DATABASE_URL') ?: getenv('SUPABASE_DB_URL');
        if ($connStr) {
            $parsed = parse_url($connStr);
            if ($parsed) {
                if (isset($parsed['host'])) self::$host = $parsed['host'];
                if (isset($parsed['port'])) self::$port = (int)$parsed['port'];
                if (isset($parsed['user'])) self::$user = $parsed['user'];
                if (isset($parsed['pass'])) self::$pass = urldecode(trim($parsed['pass'], '[]'));
                if (isset($parsed['path'])) self::$db = ltrim($parsed['path'], '/');
            }
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
     * Get singleton PDO PostgreSQL connection instance
     */
    public static function getConnection(): PDO {
        if (self::$instance !== null) {
            return self::$instance;
        }

        self::initFromEnv();

        $dsn = sprintf(
            'pgsql:host=%s;port=%d;dbname=%s;sslmode=%s',
            self::$host,
            self::$port,
            self::$db,
            self::$sslmode
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES   => false,
        ];

        try {
            self::$instance = new PDO($dsn, self::$user, self::$pass, $options);
            self::ensureVerificationColumns(self::$instance);
            return self::$instance;
        } catch (PDOException $e) {
            http_response_code(500);
            header('Content-Type: application/json; charset=UTF-8');
            echo json_encode([
                'status' => 'error',
                'message' => 'Database connection failed: ' . $e->getMessage(),
                'hint' => 'Ensure Supabase PostgreSQL credentials are valid and sslmode=require is accepted.'
            ], JSON_PRETTY_PRINT);
            exit;
        }
    }
}
