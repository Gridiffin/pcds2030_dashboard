# Fix Admin Edit Outcome Data Display Issue

## Problem

The admin edit outcome page returns all zeros when the database has actual data, while the agency edit outcome works correctly.

## Analysis

- [x] Identify data retrieval issue in admin edit outcome
- [x] Compare with working agency implementation
- [x] Check data parsing logic differences
- [x] Verify table structure handling

## Issues Found

1. Data parsing logic doesn't handle different JSON structures correctly
2. Missing proper data extraction from database row
3. Incorrect handling of legacy vs flexible structure detection
4. Table data organization logic is flawed
5. **KEY ISSUE**: Column ID vs Label mismatch in flexible structures

## Root Cause

The flexible structure stores column configurations with numeric IDs (0, 1, 2...) but uses column labels ("2022", "2023", "2024"...) as keys in the data JSON. The admin code was trying to match by column ID instead of label, causing all values to return 0.

## Solution Steps

- [x] Fix data parsing to handle both legacy and flexible structures
- [x] Ensure proper data extraction from database JSON
- [x] Fix table data organization logic
- [x] Add proper debugging for data structure detection
- [x] Fix column ID vs label mapping for flexible structures
- [x] Update JavaScript to save data with correct key format
- [ ] Test with both legacy and flexible outcomes

## Files Modified

- `app/views/admin/outcomes/edit_outcome.php` - Fixed data parsing logic and JavaScript

## Key Changes Made

1. **Fixed Column Mapping**: For flexible structures, data keys use column labels but we map them to column IDs for consistent display
2. **Enhanced Data Parsing**: Properly handles the mismatch between column configuration (ID-based) and data storage (label-based)
3. **Updated JavaScript**: Saves data using correct key format based on structure type
4. **Added Debug Logging**: Better debugging information for troubleshooting

## Testing Required

- [ ] Test editing legacy outcomes (monthly structure)
- [ ] Test editing flexible outcomes (custom structure)
- [ ] Verify data preservation during edits
- [ ] Check form submission and data saving
