# Customer Manager (CRM) Documentation

This document describes the design, implementation, schemas, and verification results of the Customer Manager (CRM) standalone module.

---

## 1. System Architecture

The CRM module follows a decoupled **Repository + Service** architecture to separate database interactions, business logic, and UI display layers:
- **Entity Model (`Customer.php`)**: Mapped value object representing the `customers` database schema.
- **Repository Layer (`CustomerRepository.php`)**: Handles raw SQL prepared statements for fetching, inserting, updating, and deleting customer rows, ensuring strict multi-tenant isolation scoped to the active `company_id`.
- **Service Layer (`CustomerService.php`)**: Encapsulates import processing, duplicate checking, fuzzy column mapping, validation of mobile numbers, and paginated searches.
- **UI Panel (`customers.php`)**: Offers standalone view grids, modals for importing or manually updating files, dynamic drop-down filters, and selections tracking.

---

## 2. Database Schema

The customer table uses performance indexes to support 100,000+ customer records:
```sql
CREATE TABLE IF NOT EXISTS customers (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    company_id     INT NOT NULL,
    full_name_ar   VARCHAR(255) DEFAULT NULL,
    full_name_en   VARCHAR(255) DEFAULT NULL,
    mobile         VARCHAR(50) NOT NULL,
    national_id    VARCHAR(50) DEFAULT NULL,
    nationality    VARCHAR(100) DEFAULT NULL,
    gender         VARCHAR(50) DEFAULT NULL,
    project_name   VARCHAR(255) DEFAULT NULL,
    program_name   VARCHAR(255) DEFAULT NULL,
    employer       VARCHAR(255) DEFAULT NULL,
    source_file    VARCHAR(255) DEFAULT NULL,
    created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    UNIQUE KEY unique_company_mobile (company_id, mobile),
    FOREIGN KEY (company_id) REFERENCES companies(id) ON DELETE CASCADE
);
```

### Performance Optimization Indexes:
- `idx_cust_company_ar`: `(company_id, full_name_ar)`
- `idx_cust_company_en`: `(company_id, full_name_en)`
- `idx_cust_company_nat_id`: `(company_id, national_id)`
- `idx_cust_company_gender`: `(company_id, gender)`
- `idx_cust_company_project`: `(company_id, project_name)`
- `idx_cust_company_program`: `(company_id, program_name)`
- `idx_cust_company_employer`: `(company_id, employer)`
- `idx_cust_company_created`: `(company_id, created_at)`

---

## 3. Spreadsheet Import Logic

The system utilizes `PhpOffice\PhpSpreadsheet` to dynamically parse uploaded `.xlsx`, `.xls`, or `.csv` spreadsheets.

### Column Mappings:
Instead of requiring a fixed Excel format, column labels are mapped dynamically using normalized substring matches:
- **Mobile Number**: `جوال`, `هاتف`, `تليفون`, `mobile`, `phone`, `cell`
- **Arabic Name**: `الاسم عربي`, `الاسم بالكامل عربي`, `الاسم الكامل باللغة العربية`, `name_ar`, `arabic`
- **English Name**: `الاسم انجليزي`, `الاسم بالكامل انجليزي`, `الاسم الكامل باللغة الانجليزية`, `name_en`, `english`
- **National ID / Residence Code**: `الهوية الوطنية`, `رقم الهوية`, `الإقامة`, `national_id`, `iqama`
- **Nationality**: `الجنسية`, `nationality`
- **Gender**: `الجنس`, `النوع`, `gender`, `sex`
- **Project Name**: `اسم المشروع`, `المشروع`, `project`
- **Program Name**: `اسم البرنامج`, `البرنامج`, `program`
- **Employer**: `جهة العمل`, `صاحب العمل`, `الشركة`, `employer`, `company`

### Merge & Deduplication Rules:
1. **Clean Mobile**: Strip all non-digit characters (`preg_replace('/[^0-9]/', '', $mobile)`). Check if length is at least 7 digits. Skip if invalid.
2. **Sheet Duplicate Guard**: Keep a track list of mobile numbers processed within the upload file. Skip subsequent matches to avoid internal file duplicates.
3. **Database Merge**:
   - If mobile exists in company: Update only columns present in row containing values. **Never overwrite existing data with empty blanks.**
   - If mobile does not exist: Insert new customer row.

---

## 4. Search & Filters

- **Global Search**: Instantly filters by Mobile, Arabic/English Names, National ID, Employer, Project, and Program.
- **Advanced Selectors**: Filter dropdowns (Gender, Nationality, Employer, Project, Program) are loaded dynamically based on distinct values found in database records.
- **Other Filters**: Filter by exact Import Date or National ID Status (Has ID, Missing ID).

---

## 5. Bulk WhatsApp Broadcast Flow

1. Check customer selections (specific checkboxes or "Select entire filtered result" trigger).
2. Query matched mobile numbers scoped under active company ID.
3. Schedule campaign by inserting a new record into `broadcast_campaigns` with target recipients and scheduled date set to `NOW()`.
   - Supports **Free Text** campaigns (with optional substitution template helpers).
   - Supports **WhatsApp Cloud Templates** by storing `template_name` and `template_language` in the campaigns table.
4. Execute campaign asynchronously: triggers background cron processor `cron/process_broadcasts.php` non-blockingly using `pclose(popen(...))`.

---

## 6. Validation & Testing Results

- **PHP Lint Compilation**: Passed. No syntax errors detected.
- **Import Validation**: Tested imports using different column mappings successfully.
- **Search & Filters**: Checked sorting and paginations.
- **Bulk WhatsApp Broadcasts**: Successfully scheduled campaigns and processed deliveries.
