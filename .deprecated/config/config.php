<?php
/**
 * Application configuration
 * 
 * Contains database credentials and other configuration settings.
 * This file should be excluded from version control.
 */

// Database configuration
define('DB_HOST', 'localhost');
define('DB_USER', 'root'); // Change this to your MySQL username (default for XAMPP is 'root')
define('DB_PASS', ''); // Change this to your MySQL password (default for XAMPP is often empty '')
define('DB_NAME', 'pcds2030_dashboard'); // Updated to correct database name

// Application settings
define('APP_NAME', 'PCDS2030 Dashboard Forestry Sector'); 
define('APP_URL', 'http://localhost/pcds2030_dashboard');
define('APP_VERSION', '1.0.0');

// Feature flags
define('MULTI_SECTOR_ENABLED', false); // Set to false to focus only on Forestry sector
define('FORESTRY_SECTOR_ID', 1);      // The ID of the Forestry sector in the database

// File paths
define('ROOT_PATH', dirname(__DIR__) . '/');
define('UPLOAD_PATH', ROOT_PATH . 'uploads/');
define('REPORT_PATH', ROOT_PATH . 'reports/');


// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);
?>
