# Setup Instructions for Contracts Feature

## 1. Database Setup

To set up the contracts functionality, you need to create the contracts table in your MySQL database.

### Option 1: Using phpMyAdmin
1. Open phpMyAdmin (http://localhost/phpmyadmin)
2. Select your `farmer_marketplace` database
3. Click on the "SQL" tab
4. Copy and paste the contents of `config/contracts_schema.sql`
5. Click "Go" to execute

### Option 2: Using MySQL command line
```bash
mysql -u root -p farmer_marketplace < config/contracts_schema.sql
```

### Option 3: Using PHP script (run once)
Create a file `setup_db.php` in the root directory:

```php
<?php
require_once 'config/database.php';

try {
    $db = getDB();
    $sql = file_get_contents('config/contracts_schema.sql');
    $db->exec($sql);
    echo "Database setup completed successfully!";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
?>
```

Then access it via: http://localhost/farmer-marketplace/setup_db.php

## 2. CSS Issues Fix

The CSS path in the contracts.php file is correct (`../css/style.css`). If CSS is not loading:

1. **Check if the CSS file exists**: Make sure `C:\xampp\htdocs\farmer-marketplace\css\style.css` exists
2. **Check file permissions**: Ensure the web server can read the CSS file
3. **Clear browser cache**: Press Ctrl+F5 to force refresh
4. **Check browser dev tools**: Open F12 and look for 404 errors in the Network tab

## 3. Sample Data

The contracts_schema.sql file includes sample contracts for testing:
- CON2024001: Active contract for Basmati Rice
- CON2024002: Pending contract for Yellow Corn  
- CON2024003: Completed contract for Organic Tomatoes

## 4. Usage

### For Buyers:
1. Access contracts: `http://localhost/farmer-marketplace/buyer/contracts.php`
2. Create new contract: `http://localhost/farmer-marketplace/buyer/manage_contracts.php`

### Features:
- View all contracts in a formatted table
- Create new contracts with auto-calculation
- Form validation and error handling
- Responsive design with inline CSS (fallback)

## 5. Troubleshooting

### Common Issues:

1. **"Buyer ID not found in session"**
   - Make sure you're logged in as a buyer
   - Check that session variables are properly set during login

2. **"Table 'contracts' doesn't exist"**
   - Run the contracts_schema.sql file as described above

3. **CSS not loading**
   - The page includes inline CSS as fallback
   - Check the main CSS file path and permissions

4. **Empty farmers/crops dropdown**
   - Make sure you have data in the `farmers` and `crops` tables
   - Run the main schema.sql first if you haven't

## 6. Database Structure

The contracts system includes:
- `contracts` table: Main contracts data
- `contract_amendments` table: Track changes to contracts
- `contract_orders` table: Link contracts to actual orders
- `contract_summary` view: Easy querying of contract data

This provides a complete contract management system for your farmer marketplace!
