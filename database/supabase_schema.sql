-- ============================================================================
-- TaraRent / RentConnect: Complete PostgreSQL Schema for Supabase
-- Converted from MySQL schema to standard PostgreSQL syntax.
-- Target DB: Supabase PostgreSQL (public schema)
-- ============================================================================

-- ----------------------------------------------------------------------------
-- 1. UNITS TABLE (Exactly 3 Units, Unlimited Tenant Capacity)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS units (
    id SERIAL PRIMARY KEY,
    unit_number VARCHAR(20) NOT NULL UNIQUE,
    floor_level INT NOT NULL DEFAULT 1,
    bedrooms INT NOT NULL DEFAULT 2,
    bathrooms DECIMAL(3,1) NOT NULL DEFAULT 1.0,
    monthly_rent DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    status VARCHAR(20) NOT NULL DEFAULT 'available',
    occupant_id INT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 2. USERS TABLE
-- Stores Landlord (Admin) and Active/Archived Tenants.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    unit_id INT NULL REFERENCES units(id) ON DELETE SET NULL,
    username VARCHAR(50) NOT NULL UNIQUE,
    name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    role VARCHAR(20) NOT NULL DEFAULT 'tenant' CHECK (role IN ('landlord', 'tenant')),
    rental_status VARCHAR(20) NOT NULL DEFAULT 'active' CHECK (rental_status IN ('active', 'moved_out', 'archived')),
    verification_status VARCHAR(30) NOT NULL DEFAULT 'unverified' CHECK (verification_status IN ('unverified', 'pending', 'pending_liveness', 'verified', 'rejected')),
    is_verified BOOLEAN NOT NULL DEFAULT FALSE,
    id_type VARCHAR(50) NULL,
    id_photo_filename VARCHAR(255) NULL,
    pending_id_path VARCHAR(255) NULL,
    pending_id_type VARCHAR(50) NULL,
    live_photo_filename VARCHAR(255) NULL,
    profile_picture VARCHAR(255) NULL,
    id_submitted_at TIMESTAMP NULL,
    id_verified_at TIMESTAMP NULL,
    verification_notes TEXT NULL,
    phone VARCHAR(30) NULL,
    birthday DATE NULL,
    emergency_contact VARCHAR(150) NULL,
    emergency_contact_name VARCHAR(100) NULL,
    emergency_contact_relationship VARCHAR(50) NULL,
    emergency_contact_phone VARCHAR(30) NULL,
    move_in_date DATE NULL,
    move_out_date DATE NULL,
    total_occupants INT NOT NULL DEFAULT 1,
    adults_count INT NOT NULL DEFAULT 1,
    kids_count INT NOT NULL DEFAULT 0,
    monthly_rent DECIMAL(10,2) NULL,
    is_active BOOLEAN NOT NULL DEFAULT TRUE,
    is_archived BOOLEAN NOT NULL DEFAULT FALSE,
    deleted_at TIMESTAMP NULL,
    archived_unit_number VARCHAR(50) NULL,
    archived_monthly_rent DECIMAL(10,2) NULL,
    archived_spaces_text VARCHAR(255) NULL,
    terms_accepted BOOLEAN NOT NULL DEFAULT FALSE,
    privacy_acknowledged BOOLEAN NOT NULL DEFAULT FALSE,
    active_tenant_unit_id INT NULL,
    active_tenant_email VARCHAR(150) NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_active_tenant_unit UNIQUE (active_tenant_unit_id),
    CONSTRAINT uq_active_tenant_email UNIQUE (active_tenant_email)
);

CREATE INDEX IF NOT EXISTS idx_users_role ON users (role);
CREATE INDEX IF NOT EXISTS idx_users_username ON users (username);
CREATE INDEX IF NOT EXISTS idx_users_unit ON users (unit_id);
CREATE INDEX IF NOT EXISTS idx_users_email ON users (email);

-- ----------------------------------------------------------------------------
-- TRIGGERS FOR USERS TABLE
-- 1. Synchronize virtual active tenant columns (for concurrency/uniqueness safety)
-- 2. Enforce strictly one active tenant per unit
-- ----------------------------------------------------------------------------
CREATE OR REPLACE FUNCTION fn_sync_active_tenant_columns()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.role = 'tenant' AND NEW.is_active = TRUE AND NEW.unit_id IS NOT NULL THEN
        NEW.active_tenant_unit_id := NEW.unit_id;
    ELSE
        NEW.active_tenant_unit_id := NULL;
    END IF;

    IF NEW.is_active = TRUE AND (NEW.is_archived = FALSE OR NEW.is_archived IS NULL) THEN
        NEW.active_tenant_email := NEW.email;
    ELSE
        NEW.active_tenant_email := NULL;
    END IF;

    NEW.updated_at := CURRENT_TIMESTAMP;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_sync_active_tenant_columns ON users;
CREATE TRIGGER trg_sync_active_tenant_columns
BEFORE INSERT OR UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION fn_sync_active_tenant_columns();

CREATE OR REPLACE FUNCTION fn_enforce_one_active_tenant()
RETURNS TRIGGER AS $$
BEGIN
    IF NEW.role = 'tenant' AND NEW.is_active = TRUE AND NEW.unit_id IS NOT NULL THEN
        IF TG_OP = 'INSERT' THEN
            IF EXISTS (
                SELECT 1 FROM users 
                WHERE unit_id = NEW.unit_id AND role = 'tenant' AND is_active = TRUE
            ) THEN
                RAISE EXCEPTION 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
                    USING ERRCODE = '45000';
            END IF;
        ELSIF TG_OP = 'UPDATE' THEN
            IF EXISTS (
                SELECT 1 FROM users 
                WHERE unit_id = NEW.unit_id AND role = 'tenant' AND is_active = TRUE AND id != OLD.id
            ) THEN
                RAISE EXCEPTION 'This unit is already occupied and has an existing tenant account. Only one tenant account is allowed per unit.'
                    USING ERRCODE = '45000';
            END IF;
        END IF;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_users_one_active_tenant ON users;
CREATE TRIGGER trg_users_one_active_tenant
BEFORE INSERT OR UPDATE ON users
FOR EACH ROW
EXECUTE FUNCTION fn_enforce_one_active_tenant();

-- ----------------------------------------------------------------------------
-- 3. PAYMENTS TABLE
-- Tracks monthly rent billing cycles and payment receipts in ₱.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    unit_id INT NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    payment_type VARCHAR(50) NOT NULL DEFAULT 'Rent',
    billing_cycle DATE NULL DEFAULT NULL,
    due_date DATE NULL DEFAULT NULL,
    base_rent_amount DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    additional_space_fee DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    additional_spaces_breakdown VARCHAR(255) NULL DEFAULT NULL,
    amount_due DECIMAL(10,2) NULL DEFAULT NULL,
    amount_paid DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    payment_date DATE NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'pending_setup' CHECK (status IN ('pending_setup', 'unpaid', 'pending', 'approved', 'rejected', 'overdue')),
    payment_method VARCHAR(50) DEFAULT 'GCash / Bank Transfer',
    receipt_filename VARCHAR(255) NULL,
    verified_by_landlord BOOLEAN NOT NULL DEFAULT FALSE,
    verification_notes TEXT NULL,
    verified_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_payments_tenant ON payments (tenant_id);
CREATE INDEX IF NOT EXISTS idx_payments_cycle ON payments (billing_cycle);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments (status);
CREATE INDEX IF NOT EXISTS idx_payments_unit ON payments (unit_id);

-- ----------------------------------------------------------------------------
-- 4. MAINTENANCE TICKETS TABLE
-- Tracks repair work orders categorized per unit with actual repair costs in ₱.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS maintenance_tickets (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    unit_id INT NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    title VARCHAR(150) NOT NULL,
    description TEXT NOT NULL,
    photo_path VARCHAR(1000) NULL,
    category VARCHAR(50) NOT NULL DEFAULT 'General',
    priority VARCHAR(20) NOT NULL DEFAULT 'medium' CHECK (priority IN ('low', 'medium', 'high', 'urgent')),
    status VARCHAR(20) NOT NULL DEFAULT 'open' CHECK (status IN ('open', 'in_progress', 'resolved', 'closed')),
    actual_cost DECIMAL(10,2) NOT NULL DEFAULT 0.00,
    resolution_notes TEXT NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    resolved_at TIMESTAMP NULL
);

CREATE INDEX IF NOT EXISTS idx_tickets_tenant ON maintenance_tickets (tenant_id);
CREATE INDEX IF NOT EXISTS idx_tickets_category ON maintenance_tickets (category);
CREATE INDEX IF NOT EXISTS idx_tickets_unit ON maintenance_tickets (unit_id);
CREATE INDEX IF NOT EXISTS idx_tickets_status ON maintenance_tickets (status);

-- ----------------------------------------------------------------------------
-- 5. ADDITIONAL RENTAL SPACES TABLE (Parking / Extra Lots)
-- Exactly 2 available rentable spaces at ₱1,500.00/month each.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS additional_spaces (
    id SERIAL PRIMARY KEY,
    space_number INT NOT NULL UNIQUE,
    space_name VARCHAR(50) NOT NULL,
    monthly_fee DECIMAL(10,2) NOT NULL DEFAULT 1500.00,
    status VARCHAR(20) NOT NULL DEFAULT 'available' CHECK (status IN ('available', 'occupied')),
    assigned_tenant_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    assigned_unit_id INT NULL REFERENCES units(id) ON DELETE SET NULL,
    rented_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_spaces_status ON additional_spaces (status);
CREATE INDEX IF NOT EXISTS idx_spaces_tenant ON additional_spaces (assigned_tenant_id);

-- ----------------------------------------------------------------------------
-- 6. APARTMENT SETTINGS TABLE
-- Global property settings, standard deposit, and active lease tracking.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS apartment_settings (
    id SERIAL PRIMARY KEY,
    apartment_name VARCHAR(100) NOT NULL DEFAULT 'TaraRent Residences',
    address VARCHAR(255) NOT NULL DEFAULT '742 Evergreen Boulevard, Portland, OR 97201',
    monthly_rent DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    security_deposit DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    lease_start DATE NULL,
    lease_end DATE NULL,
    active_tenant_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ----------------------------------------------------------------------------
-- 7. PASSWORD RESETS TABLE
-- Secure token- and 6-digit verification code-based password recovery requests.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS password_resets (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(191) NOT NULL,
    token_hash VARCHAR(64) NOT NULL,
    ip_address VARCHAR(45) NULL,
    attempts INT NOT NULL DEFAULT 0,
    max_attempts INT NOT NULL DEFAULT 5,
    expires_at TIMESTAMP NOT NULL,
    used_at TIMESTAMP NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_token_hash ON password_resets (token_hash);
CREATE INDEX IF NOT EXISTS idx_user_resets ON password_resets (user_id, expires_at);

-- ----------------------------------------------------------------------------
-- 8. EMAIL LOGS TABLE
-- Audit log of all outgoing PHPMailer deliveries & scheduled notification tracking.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS email_logs (
    id SERIAL PRIMARY KEY,
    recipient_user_id INT NULL REFERENCES users(id) ON DELETE SET NULL,
    recipient_email VARCHAR(191) NOT NULL,
    recipient_name VARCHAR(100) NULL,
    subject VARCHAR(255) NOT NULL,
    template_type VARCHAR(50) NOT NULL,
    reference_id INT NULL,
    reference_type VARCHAR(50) NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'failed', 'mock_sent')),
    error_message TEXT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_email_logs_user ON email_logs (recipient_user_id);
CREATE INDEX IF NOT EXISTS idx_email_dedup ON email_logs (recipient_email, template_type, reference_id, reference_type);
CREATE INDEX IF NOT EXISTS idx_sent_at ON email_logs (sent_at);

-- ----------------------------------------------------------------------------
-- 9. ANNOUNCEMENTS TABLES
-- Owner rent due & community announcements broadcasted to all or selected tenants.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS announcements (
    id SERIAL PRIMARY KEY,
    owner_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    due_date DATE NULL,
    target_type VARCHAR(20) NOT NULL DEFAULT 'all' CHECK (target_type IN ('all', 'selected')),
    recipient_count INT NOT NULL DEFAULT 0,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_announcements_owner ON announcements (owner_id);

CREATE TABLE IF NOT EXISTS announcement_recipients (
    id SERIAL PRIMARY KEY,
    announcement_id INT NOT NULL REFERENCES announcements(id) ON DELETE CASCADE,
    tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    email VARCHAR(191) NOT NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'sent' CHECK (status IN ('sent', 'failed', 'mock_sent')),
    error_message TEXT NULL,
    sent_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ann_rec_ann ON announcement_recipients (announcement_id);
CREATE INDEX IF NOT EXISTS idx_ann_rec_tenant ON announcement_recipients (tenant_id);

-- ----------------------------------------------------------------------------
-- 10. TENANT REPORTS TABLE
-- General non-maintenance tenant requests (occupants, pets, modifications, inquiries).
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS tenant_reports (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    unit_id INT NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    category VARCHAR(100) NOT NULL,
    subject VARCHAR(180) NOT NULL,
    details TEXT NOT NULL,
    attachment_path VARCHAR(1000) NULL DEFAULT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'Pending' CHECK (status IN ('Pending', 'Approved', 'Rejected', 'Acknowledged')),
    owner_remarks TEXT NULL DEFAULT NULL,
    reviewed_by INT NULL REFERENCES users(id) ON DELETE SET NULL,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    reviewed_at TIMESTAMP NULL DEFAULT NULL,
    updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_tr_tenant ON tenant_reports (tenant_id);
CREATE INDEX IF NOT EXISTS idx_tr_unit ON tenant_reports (unit_id);
CREATE INDEX IF NOT EXISTS idx_tr_status ON tenant_reports (status);
CREATE INDEX IF NOT EXISTS idx_tr_created ON tenant_reports (created_at);

-- ----------------------------------------------------------------------------
-- 11. NOTIFICATIONS TABLE
-- Real-time in-app notifications for Landlord and Tenants.
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS notifications (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    role VARCHAR(20) NOT NULL DEFAULT 'tenant' CHECK (role IN ('landlord', 'tenant')),
    type VARCHAR(50) NOT NULL DEFAULT 'general',
    title VARCHAR(255) NOT NULL,
    message TEXT NOT NULL,
    link VARCHAR(255) NULL DEFAULT NULL,
    reference_id INT NULL DEFAULT NULL,
    reference_type VARCHAR(50) NULL DEFAULT NULL,
    is_read BOOLEAN NOT NULL DEFAULT FALSE,
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    read_at TIMESTAMP NULL DEFAULT NULL
);

CREATE INDEX IF NOT EXISTS idx_notifications_user_read ON notifications (user_id, is_read);
CREATE INDEX IF NOT EXISTS idx_notifications_user_created ON notifications (user_id, created_at);
CREATE INDEX IF NOT EXISTS idx_notifications_ref ON notifications (reference_id, reference_type);

-- ----------------------------------------------------------------------------
-- 12. LEASES TABLE (Optional / Unit Folder Leases)
-- ----------------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS leases (
    id SERIAL PRIMARY KEY,
    tenant_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    unit_id INT NOT NULL REFERENCES units(id) ON DELETE CASCADE,
    monthly_rent DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    security_deposit DECIMAL(10,2) NOT NULL DEFAULT 3000.00,
    lease_start DATE NOT NULL,
    lease_end DATE NULL,
    status VARCHAR(20) NOT NULL DEFAULT 'active',
    created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
);

-- ============================================================================
-- INITIAL SEED DATA
-- ============================================================================

-- 1. Standard Apartment Units (Unit 1, Unit 2, Unit 3 at ₱3,000.00/month)
INSERT INTO units (id, unit_number, floor_level, bedrooms, bathrooms, monthly_rent) VALUES
(1, 'Unit 1', 1, 1, 1.0, 3000.00),
(2, 'Unit 2', 1, 2, 1.0, 3000.00),
(3, 'Unit 3', 2, 3, 1.0, 3000.00)
ON CONFLICT (id) DO UPDATE 
SET unit_number = EXCLUDED.unit_number, monthly_rent = EXCLUDED.monthly_rent;

-- 2. Landlord / Property Owner Account (Lead Dimaculangan)
-- admin: admin123 ($2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO)
INSERT INTO users (id, unit_id, username, name, email, password_hash, role, phone, birthday, emergency_contact, move_in_date, is_active, is_verified, created_at) VALUES
(1, NULL, 'admin', 'Lead Dimaculangan', 'TaraRent2026@gmail.com', '$2y$10$L1oJw/e9iaOxHBBWA6p0G.EEPbgJ8jKQxXLEnjzxwY0DSzno7igyO', 'landlord', '+63 917 123 4567', '1985-11-20', 'Property Management Office', '2024-01-01', TRUE, TRUE, CURRENT_TIMESTAMP)
ON CONFLICT (id) DO UPDATE 
SET email = EXCLUDED.email, password_hash = EXCLUDED.password_hash;

-- 3. Default Property / Apartment Settings
INSERT INTO apartment_settings (id, apartment_name, address, monthly_rent, security_deposit) VALUES
(1, 'TaraRent Residences', '742 Evergreen Boulevard, Portland, OR 97201', 3000.00, 3000.00)
ON CONFLICT (id) DO UPDATE 
SET apartment_name = EXCLUDED.apartment_name, monthly_rent = EXCLUDED.monthly_rent;

-- 4. Additional Spaces (Space 1 & Space 2 at ₱1,500.00/month)
INSERT INTO additional_spaces (id, space_number, space_name, monthly_fee, status) VALUES
(1, 1, 'Space 1', 1500.00, 'available'),
(2, 2, 'Space 2', 1500.00, 'available')
ON CONFLICT (space_number) DO UPDATE 
SET space_name = EXCLUDED.space_name, monthly_fee = EXCLUDED.monthly_fee;

-- 5. Advance Sequences past the seeded IDs
SELECT setval('units_id_seq', (SELECT COALESCE(MAX(id), 1) FROM units));
SELECT setval('users_id_seq', (SELECT COALESCE(MAX(id), 1) FROM users));
SELECT setval('apartment_settings_id_seq', (SELECT COALESCE(MAX(id), 1) FROM apartment_settings));
SELECT setval('additional_spaces_id_seq', (SELECT COALESCE(MAX(id), 1) FROM additional_spaces));
