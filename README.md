# Belmont Online Ticketing System

Internal ticketing and support system for Cebu Belmont, Inc. built with PHP and MySQL.

---

## Requirements

- PHP 8.0+ (`pdo_mysql`, `mbstring`, `curl`, `json`, `fileinfo`)
- MySQL 5.7+ / MariaDB 10.3+
- Apache (with `mod_rewrite`) or Nginx

---

## Quick Setup

### 1. Database Configuration
Create the database in MySQL:
```sql
CREATE DATABASE `belmont_helpdesk` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Import schema and migration files in order:
```bash
mysql -u root -p belmont_helpdesk < database/schema.sql
mysql -u root -p belmont_helpdesk < database/001_performance_metrics.sql
mysql -u root -p belmont_helpdesk < database/002_feature_set2.sql
mysql -u root -p belmont_helpdesk < database/003_advanced_features.sql
mysql -u root -p belmont_helpdesk < database/004_dept_shared_email.sql
mysql -u root -p belmont_helpdesk < database/005_kb_content.sql
mysql -u root -p belmont_helpdesk < database/006_dept_admin_role.sql
mysql -u root -p belmont_helpdesk < database/007_kb_source_ticket.sql
mysql -u root -p belmont_helpdesk < database/008_legacy_migration.sql
```

### 2. Environment Setup
Copy the environment template:
```bash
cp .env.example .env
```
Update `.env` with your local database credentials and mail/API configurations.

### 3. Permissions
Ensure `uploads/` directory has write permissions for ticket file attachments and user avatars.

---

## Default Accounts

| Role | Email | Default Password |
| :--- | :--- | :--- |
| Admin | `admin@belmont.ph` | `Admin@123` |
| Staff | `it@belmont.ph` | `Staff@123` |

---

## User Roles

| Role | Scope | Description |
| :--- | :--- | :--- |
| `admin` | Global | Full system access (users, departments, categories, settings, logs, migrations). |
| `dept_admin` | Department | Department head access (manages tickets, reports, and staff within their department). |
| `staff` | Department | Handles tickets, adds public replies/internal notes, manages KB articles. |
| `user` | Personal | Submits tickets, tracks requests, submits CSAT ratings, browses KB. |

---

## Core Modules

- **Tickets**: Ticket lifecycle (Open, In Progress, Pending, Resolved, Closed), priority levels, attachments, internal notes, canned responses, and auto-close.
- **SLA & Escalation**: SLA deadline calculation and escalation routing for overdue tickets.
- **Knowledge Base**: Searchable articles and single-click article creation from resolved tickets.
- **Reports & CSAT**: Department metrics, resolution times, SLA compliance, and customer satisfaction ratings.
- **AI / Automation**: Smart subject suggestions, category/priority auto-classification, duplicate detection, and automated reply drafts via Groq / Cerebras / SambaNova (with local heuristic fallback).
- **Legacy Migration**: Web tool (`views/admin/migrate.php`) for importing historical ticket data.

---

## Environment Variables (.env)

| Key | Default | Description |
| :--- | :--- | :--- |
| `DB_HOST` | `localhost` | Database host |
| `DB_PORT` | `3306` | Database port |
| `DB_NAME` | `belmont_helpdesk` | Database name |
| `DB_USER` | `root` | Database username |
| `DB_PASS` | ` ` | Database password |
| `DB_CHARSET` | `utf8mb4` | Database charset |
| `APP_NAME` | `"Belmont Online Ticketing System"` | Application title |
| `COMPANY` | `"Cebu Belmont, Inc."` | Company name |
| `MAIL_ENABLED` | `false` | Enable outbound SMTP emails |
| `SMTP_HOST` | `smtp.gmail.com` | SMTP host |
| `SMTP_PORT` | `587` | SMTP port |
| `SMTP_USER` | ` ` | SMTP username |
| `SMTP_PASS` | ` ` | SMTP password |
| `MAIL_FROM` | ` ` | Sender email address |
| `MAIL_FROM_NAME` | `"Cebu Belmont, Inc. Helpdesk"` | Sender display name |
| `AI_ENABLED` | `true` | Enable AI assistant features |
| `AI_TIMEOUT` | `30` | AI request timeout (seconds) |
| `GROQ_API_KEY` | ` ` | Groq API Key |
| `GROQ_MODEL` | `openai/gpt-oss-20b` | Groq model identifier |
| `CEREBRAS_API_KEY` | ` ` | Cerebras API Key |
| `SAMBANOVA_API_KEY` | ` ` | SambaNova API Key |

---

## Scheduled Tasks / Cron

| Schedule | Command | Action |
| :--- | :--- | :--- |
| `*/5 * * * *` | `php api/sla_check.php` | Checks SLA response/resolution deadlines |
| `*/15 * * * *` | `php api/sla_escalation.php` | Escalates breached tickets |
| `0 0 * * *` | `php api/auto_close.php` | Auto-closes inactive resolved tickets |
| `0 8 * * *` | `php api/email_digest.php` | Sends pending ticket digest to department heads |

---

## Directory Layout

```
Belmont-ticketing-system/
|-- api/                      # API endpoints (AI, SLA, KB, auto-close, notifications)
|-- assets/                   # Static CSS and JavaScript files
|-- config/                   # Database and environment loader
|-- database/                 # Base schema and SQL migration files
|-- includes/                 # Session, authentication, mailer, and helper functions
|-- public/                   # Public images and branding assets
|-- uploads/                  # User avatars and ticket attachments
|-- views/                    # Application pages (admin, tickets, reports, kb, profile)
|-- .env.example              # Environment variables template
|-- forgot-password.php       # Password reset request
|-- index.php                 # Main dashboard
|-- login.php                 # Authentication page
|-- logout.php                # Logout handler
`-- reset-password.php        # Password reset confirmation
```
