# 🏢 TaraRent: Apartment Management, Maintenance Ticketing & Payment Tracker

**TaraRent** (formerly RentConnect) is a full-featured, claymorphic property management and business intelligence platform built for modern residential properties. It combines a clean, tactile user interface with robust multi-tenant authorization, financial auditing, biometric face verification, OCR receipt processing, and automated move-out workflows.

---

## 🌟 Key Highlights & Technologies

- **Backend**: PHP 8.2+ (MVC-inspired service architecture, PDO, strict types)
- **Database**: **Supabase PostgreSQL** (production cloud pooler) with backward compatibility for **local MySQL**
- **Styling**: Claymorphism Design System + **Tailwind CSS** (curated 60-30-10 color hierarchy)
- **Frontend Interaction**: Vanilla JavaScript (ES6+), Lucide Icons
- **Biometric & Vision AI**:
  - **face-api.js**: 68-point facial landmark detection and live face verification
  - **Tesseract.js**: Client-side OCR for automatic payment receipt amount and reference scanning
- **Email Service**: **PHPMailer** via SMTP for transactional notifications (bills, verifications, status updates)
- **Currency**: Standard Philippine Peso (**₱**) across all calculations, tables, and charts

---

## 🏛️ System Architecture & Core Modules

### 1. 🏠 Property & Unit Architecture
- Manages **exactly 3 core apartment units**:
  - **Unit 1**: ₱3,000.00/mo
  - **Unit 2**: ₱3,000.00/mo
  - **Unit 3**: ₱3,000.00/mo
- **Additional Spaces**: Support for optional add-ons (e.g., **Space 1** & **Space 2** parking slots at ₱1,500.00/mo).
- **Decoupled Unit Vacancy Logic**: Marking a tenant as moved out immediately releases the physical unit to `available` status for prospective renters, regardless of outstanding balances.

### 2. 🔐 Authentication, Roles & Security
- **Role-Based Access Control**:
  - 👑 **Landlord (Admin)**: Full administrative oversight, financial audits, rent management, ticket resolution, and tenant creation.
  - 🔑 **Tenant**: Access to assigned unit dashboard, repair requests, payment uploads, approval requests, and personal settings.
- **Biometric Face Verification**: Fast login verification using neural-network facial landmark recognition.
- **Identity & Liveness Verification**:
  - Tenants submit valid government ID photos and take live photo captures.
  - Landlord audits and verifies resident identity before granting full access.
- **Security Re-authentication**: Critical administrative actions (such as authorizing a tenant move-out or account archiving) require the landlord's password for verification.

### 3. 💳 Financial Settlement & Payment Verification
- **Payment Workflow**:
  1. Tenant uploads a payment receipt (GCash, Maya, Bank Transfer) with optional Tesseract.js OCR assistance.
  2. The record enters `pending` status.
  3. Landlord inspects proof files via the dedicated **Payment Verification & Audit** ledger.
  4. Landlord approves or rejects with custom feedback notes, updating financial balances in real time.
- **Audit Engine**: Tracks total billing, deposits, paid amounts, overdue bills, and remaining balances per tenant.

### 4. 📦 Decoupled Move-Out & Automatic Archiving
- When a tenant vacates:
  - **Fully Settled Tenants**: Account is automatically archived, preserving personal history, payment ledgers, and maintenance records.
  - **Tenants with Outstanding Dues**: The physical unit is marked `available` immediately, but the tenant account enters restricted **Debt Collection Mode** (Dashboard & Payment Upload only) until all balances are fully settled.

### 5. 📑 Report & Approval Request System (60-30-10 Tabbed UI)
Located in the Tenant Portal with a consolidated tabbed interface:
- **Submit Request Tab** (30% Medium Brown `#B89B72`):
  - Request Categories: *Unit Customization, Pet Addition, Appliance or Wi-Fi Installation, Additional Occupant, Vehicle Parking Request, Move-Out or Lease Renewal Notice, Key Duplication or Replacement*.
  - Request specification input, detailed description, and drag-and-drop document upload.
- **Status Tracker Tab** (60% Light Beige `#E8D8B0`):
  - Historical requests rendered with status progress bars, owner review notes, and read-only detail inspection.

---

## 🗄️ Database Architecture (`13 Tables`)

| Table | Purpose |
|---|---|
| `units` | Apartment unit inventory, floor levels, specifications, and live occupancy status |
| `users` | Accounts (Landlord & Tenants), verification state, profile images, and move-in/out records |
| `payments` | Monthly billing ledger, amount due/paid, receipt files, OCR data, and approval status |
| `maintenance_tickets` | Maintenance repair requests, trade categories, urgency priority, and repair costs |
| `additional_spaces` | Rented add-ons (parking spots, storage) assigned to active tenants |
| `apartment_settings` | Global building configurations, default rent amounts, and deposit policies |
| `tenant_reports` | Tenant approval requests (pets, modifications, parking, lease renewal) |
| `notifications` | System notifications and status alerts for users |
| `announcements` | Property-wide broadcasts created by the landlord |
| `announcement_recipients` | Delivery and read tracking per tenant for broadcast messages |
| `email_logs` | Audit trail of outgoing SMTP emails sent by the system |
| `password_resets` | Cryptographic tokens for secure password recovery |
| `leases` | Historical and active lease agreements and date ranges |

---

## 🚀 Installation & Local Setup

### Prerequisites
- **XAMPP** (Apache + PHP 8.2+)
- **PHP Extensions**: `pdo_pgsql`, `pdo_mysql`, `curl`, `mbstring`, `openssl`, `fileinfo` enabled in `php.ini`
- Web browser (Chrome, Edge, or Firefox recommended)

---

### Step 1: Clone or Place into `htdocs`
Place the project inside your XAMPP web root:
```
C:\xampp\htdocs\RentConnect
```

To access the project via **`http://localhost/TaraRent`**, create a directory junction in PowerShell (Run as Administrator):
```powershell
New-Item -ItemType Junction -Path "C:\xampp\htdocs\TaraRent" -Target "C:\xampp\htdocs\RentConnect"
```

---

### Step 2: Environment Configuration (`.env`)
Copy `.env.example` to `.env` (or update existing `.env`):
```ini
APP_NAME=TaraRent
APP_URL=http://localhost/TaraRent

# Supabase PostgreSQL Configuration
DB_HOST=aws-1-ap-southeast-1.pooler.supabase.com
DB_PORT=6543
DB_NAME=postgres
DB_USER=postgres.asqnfbhzgqdlqxnuuugu
DB_PASSWORD=YOUR_SUPABASE_PASSWORD

# Supabase Cloud Storage Configuration (Render Ephemeral Storage Fix)
SUPABASE_URL=https://asqnfbhzgqdlqxnuuugu.supabase.co
SUPABASE_SERVICE_KEY=YOUR_SUPABASE_SERVICE_ROLE_KEY

# SMTP Mail Settings (PHPMailer)
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_USER=your_email@gmail.com
MAIL_PASS=your_app_password
MAIL_FROM=your_email@gmail.com
MAIL_FROM_NAME=TaraRent
```

---

### Step 3: Database Migration

#### Option A: Supabase PostgreSQL (Recommended)
1. Open your **Supabase Dashboard** -> **SQL Editor**.
2. Run the SQL script located in [`database/supabase_schema.sql`](database/supabase_schema.sql).
3. All 13 tables, constraints, foreign keys, and default seed records will be created.

#### Option B: Local MySQL Fallback
1. Start **Apache** and **MySQL** in XAMPP.
2. Open **phpMyAdmin** (`http://localhost/phpmyadmin`) and import [`database/schema.sql`](database/schema.sql).
3. Adjust `config/db.php` if using local MySQL.

---

### Step 4: Access TaraRent
Start Apache in XAMPP and navigate to:
👉 **`http://localhost/TaraRent`**

---

## 🔑 Default Credentials

| Role | Username | Password | Notes |
|---|---|---|---|
| **Landlord / Admin** | `admin` | `admin123` | Full access to landlord dashboard & management |
| **Tenant 1** | `tenant1` | `111111` | Juan Dela Cruz (Assigned to Unit 1) |
| **Tenant 2** | `tenant2` | `222222` | Maria Santos (Assigned to Unit 2) |

---

## 📁 Project Structure

```
TaraRent/
├── admin_dashboard.php        # Landlord management portal (units, tenants, payments, tickets)
├── tenant_portal.php          # Tenant resident portal (dashboard, maintenance, reports, payments)
├── login.php                  # Centralized secure login with facial recognition support
├── login_face_verify.php      # Biometric face matching verification endpoint
├── forgot_password.php        # Self-service password recovery flow
├── reset_password.php         # Token-based password reset completion
├── api/                       # RESTful internal API endpoints
│   ├── announcement_api.php   # Broadcast announcements
│   ├── auth_api.php           # Session and biometric authentication
│   ├── notification_api.php   # Real-time notifications
│   ├── payment_api.php        # Manual payment verification & ledger operations
│   ├── profile_api.php        # User profile, password, and avatar updates
│   ├── report_api.php         # Tenant general reports & approval requests
│   └── tenant_directory_api.php # Tenant CRUD, archiving, move-out settlement
├── assets/                    # Static assets
│   ├── css/                   # Claymorphism & tenant styling stylesheets
│   ├── js/                    # Sidebar controller, face-api, camera scripts
│   ├── models/                # Neural network model weights for face-api.js
│   └── images/                # Icons, logos, and UI placeholders
├── config/                    # System configurations
│   ├── auth.php               # Authentication & session security helpers
│   ├── db.php                 # Unified PDO database connection (Supabase / MySQL)
│   └── mail.php               # PHPMailer configuration helper
├── database/                  # Schema definitions & migrations
│   ├── schema.sql             # Legacy MySQL schema
│   └── supabase_schema.sql    # Current production PostgreSQL schema for Supabase
├── includes/                  # Core domain services
│   ├── access_control.php     # Tenant verification & password validation
│   ├── email_service.php      # Transactional notification dispatcher
│   ├── notification_service.php # In-app notification dispatcher
│   ├── sidebar.php            # Shared claymorphic sidebar component
│   └── tenant_service.php     # Move-out settlement audit & auto-archiving service
└── uploads/                   # User-uploaded documents, receipts, IDs, and avatars
```

---

## 🛡️ License & Maintenance

Developed for property owners seeking an organized, accountable, and modern rental administration workflow. Preserves complete financial and maintenance records for long-term auditability.
