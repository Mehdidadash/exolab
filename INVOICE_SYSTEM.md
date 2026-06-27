# EXOLAB Invoice & Payment System

This document explains the invoice and payment management system added to the EXOLAB admin panel.

## Features

### 1. Invoice Management (`/admin/invoices.php`)
- Create, edit, and delete invoices
- Track invoice status (paid/unpaid)
- Generate PDF invoices with Farsi dates and RTL layout
- Fields:
  - Invoice number
  - Doctor name, phone, email
  - Items description
  - Total amount (in Tomans)
  - Payment status
  - Invoice and due dates
  - Notes

### 2. Payment Recording (`/admin/payments.php`)
- Record doctor payments with multiple methods:
  - **کارت به کارت** (Card to Card Transfer)
  - **شبا** (SHEBA/IBAN Transfer)
  - **نقدی** (Cash)
  - **چک** (Check)
- Link payments to invoices (optional)
- Track bank account receiving the payment
- Fields:
  - Doctor name
  - Amount
  - Payment method
  - Payment date
  - Transaction number (optional)
  - Bank account
  - Notes

### 3. Bank Accounts Management (`/admin/bank_accounts.php`)
- Manage bank accounts for receiving payments
- Multiple account support (yours, wife's, partner's, etc.)
- Fields:
  - Account owner name
  - Bank name
  - Account number
  - IBAN/SHEBA
  - Active status
  - Notes

### 4. Doctor Debt Tracking
- Automatic debt calculation via `calculateDoctorDebt($doctor_name)`
- Compares invoices total vs. payments total for each doctor
- Can be used to create a debt report

### 5. PDF Invoice Generation
- URL: `/admin/invoice_pdf.php?id=<invoice_id>`
- Features:
  - Farsi (Persian) dates using Jalali calendar
  - RTL (Right-to-Left) layout
  - Company logo (EXOLAB_LOGO_FULL.svg)
  - Professional A4 format
  - Farsi numerals
  - Color scheme: Navy (#0F172A), Cyan (#06B6D4)

## Database Schema

### Tables Created

#### `bank_accounts`
```sql
- id (INT, Primary Key)
- account_owner_name (VARCHAR 200)
- bank_name (VARCHAR 200)
- account_number (VARCHAR 50)
- iban_sheba (VARCHAR 50)
- is_active (TINYINT)
- notes (TEXT)
- created_at, updated_at
```

#### `doctor_invoices`
```sql
- id (INT, Primary Key)
- invoice_number (VARCHAR 50, UNIQUE)
- doctor_name (VARCHAR 255)
- doctor_phone (VARCHAR 20)
- doctor_email (VARCHAR 120)
- items_description (TEXT)
- total_amount (DECIMAL 15,2)
- payment_status (VARCHAR 20: 'unpaid', 'paid')
- invoice_date (DATE)
- due_date (DATE)
- notes (TEXT)
- created_at, updated_at
```

#### `doctor_payments`
```sql
- id (INT, Primary Key)
- invoice_id (INT, Foreign Key → doctor_invoices)
- doctor_name (VARCHAR 255)
- amount (DECIMAL 15,2)
- payment_method (VARCHAR 50: کارت به کارت, شبا, نقدی, چک)
- payment_date (DATE)
- transaction_number (VARCHAR 100)
- bank_account_id (INT, Foreign Key → bank_accounts)
- notes (TEXT)
- created_at, updated_at
```

## Dependencies

- **mpdf/mpdf** - PDF generation with RTL support
- **morilog/jalali** - Jalali (Persian) calendar conversion

Installed via Composer:
```bash
composer require mpdf/mpdf morilog/jalali
```

## Usage

### Creating an Invoice
1. Go to Admin → Invoices
2. Click "ایجاد فاکتور جدید"
3. Fill in invoice details
4. Save

### Recording a Payment
1. Go to Admin → Payments
2. Click "ثبت پرداخت جدید"
3. Select invoice (optional), enter doctor name and payment details
4. Save

### Setting Up Bank Accounts
1. Go to Admin → Bank Accounts
2. Click "افزودن حساب بانکی جدید"
3. Enter account details
4. Save

### Generating Invoice PDF
1. Open Invoices list
2. Click "پیش‌نمایش PDF" next to an invoice
3. View or download the PDF in Farsi

### Checking Doctor Debt
- Use the database function: `calculateDoctorDebt($doctor_name)`
- Returns: total invoices minus total payments for that doctor

## Helper Functions

### Date Formatting
- `toJalaliDate($gregorianDate)` - Convert to Jalali format (YYYY/MM/DD)
- `toJalaliDateFormatted($gregorianDate)` - Formatted with Persian digits
- `toJalaliDateWithMonth($gregorianDate)` - Full date with month name (e.g., "۱۵ فروردین ۱۴۰۳")

### Amount Formatting
- `formatAmountToman($value)` - Format as currency with Persian numerals (e.g., "۱۰٬۰۰۰ تومان")

### Invoice/Payment Functions
- `getAllInvoices()` - Get all invoices
- `getInvoice($id)` - Get single invoice
- `saveInvoice($data)` - Create/update invoice
- `deleteInvoice($id)` - Delete invoice
- `getAllPayments()` - Get all payments with bank info
- `getPayment($id)` - Get single payment
- `savePayment($data)` - Create/update payment
- `deletePayment($id)` - Delete payment
- `getAllBankAccounts()` - Get all bank accounts
- `getBankAccount($id)` - Get single account
- `saveBankAccount($data)` - Create/update account
- `deleteBankAccount($id)` - Delete account
- `calculateDoctorDebt($doctor_name)` - Get doctor's current debt

## Files Added/Modified

### New Files
- `/admin/invoices.php` - Invoices list
- `/admin/invoice_form.php` - Invoice create/edit
- `/admin/save_invoice.php` - Save invoice endpoint
- `/admin/delete_invoice.php` - Delete invoice endpoint
- `/admin/invoice_pdf.php` - PDF generator
- `/admin/payments.php` - Payments list
- `/admin/payment_form.php` - Payment create/edit
- `/admin/save_payment.php` - Save payment endpoint
- `/admin/delete_payment.php` - Delete payment endpoint
- `/admin/bank_accounts.php` - Bank accounts list
- `/admin/bank_account_form.php` - Bank account create/edit
- `/admin/save_bank_account.php` - Save bank account endpoint
- `/admin/delete_bank_account.php` - Delete bank account endpoint
- `/vendor/` - Composer dependencies (mpdf/mpdf, morilog/jalali)

### Modified Files
- `/install.php` - Added new table creation SQL
- `/db.php` - Added invoice/payment helper functions
- `/includes/helpers.php` - Added Jalali date helpers
- `/admin/auth.php` - Updated navigation menu
- `/admin/dashboard.php` - Added invoice/payment links

## Notes

- All dates are stored in Gregorian format in the database
- Jalali dates are converted for display only
- Payment methods are stored in Persian
- All amount calculations are in Tomans
- PDF invoices include RTL layout, Farsi numerals, and Jalali dates
- Bank accounts support multiple receivers (you, wife, partner, etc.)

## Future Enhancements

- Payment reconciliation reports
- Doctor debt reminder system
- Invoice templates customization
- Automatic payment status update from payments
- Export invoices/payments to Excel
- SMS/Email notifications for due invoices
