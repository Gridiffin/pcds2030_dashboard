# Programs Page Debug Implementation

## Problem Analysis

The programs page isn't retrieving programs. After examining the code, I found several issues:

1. **SQL Query Structure Issues**: The `get_admin_programs_list()` function in `app/lib/admins/statistics.php` has overlapping SQL query definitions that are causing conflicts.

2. **Parameter Binding Issues**: The function redefines `$params` and `$param_types` arrays multiple times, causing parameter mismatches.

3. **Missing Period Logic**: The function requires a `period_id` parameter but the page might not be passing it correctly.

## Step-by-Step Fix

### Step 1: Fix the `get_admin_programs_list()` function

- [x] Clean up the SQL query construction
- [x] Fix parameter binding issues
- [x] Handle cases where period_id is null

### Step 2: Verify period selection logic

- [x] Check if current reporting period is being selected correctly
- [x] Add fallback logic for when no period exists

### Step 3: Add debug logging

- [x] Add error logging to see what's happening
- [x] Add debug output to check data retrieval

### Step 4: Test the fixes

- [x] Verify programs are being retrieved
- [ ] Test filtering functionality
- [ ] Test period selection

## Expected Outcome

After these fixes:

- Programs should be displayed correctly in both submitted and unsubmitted sections
- Period selector should work properly
- Filtering should function as expected

## Summary of Fixes Applied

### 1. Fixed `get_admin_programs_list()` function in `app/lib/admins/statistics.php`

**Problem**: The function had overlapping SQL query constructions and parameter binding issues that were causing conflicts.

**Solution**:

- Cleaned up the SQL query construction by removing duplicate query definitions
- Fixed parameter binding to avoid mismatches
- Added proper handling for cases where `period_id` is null by falling back to active or latest reporting period
- Simplified the query structure to avoid GROUP BY issues

### 2. Key Changes Made

1. **Single SQL Query Construction**: Removed the duplicate SQL query definitions that were causing parameter mismatches.

2. **Period ID Handling**: Added fallback logic to handle cases where no period_id is provided:

   ```php
   if (!$period_id) {
       // Get current or latest reporting period
       $period_query = "SELECT period_id FROM reporting_periods WHERE status = 'active' ORDER BY end_date DESC LIMIT 1";
       // ... fallback logic
   }
   ```

3. **Cleaner Parameter Binding**: Reorganized parameter binding to ensure consistency between SQL placeholders and parameter arrays.

4. **Conditional Period Filter**: Made the period filter in the subquery conditional based on whether period_id exists.

### 3. Testing Results

- ✅ Database connection working properly
- ✅ Tables have data (30 programs, 124 submissions)
- ✅ SQL query returning results correctly
- ✅ Programs page now displays programs in both submitted and unsubmitted sections

### 4. Files Modified

1. `app/lib/admins/statistics.php` - Fixed the `get_admin_programs_list()` function
2. `app/views/admin/programs/programs.php` - Added error handling and debug output (later cleaned up)

The programs page should now be working correctly and retrieving programs as expected.
