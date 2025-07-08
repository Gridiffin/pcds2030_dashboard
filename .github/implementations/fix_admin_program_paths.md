# Fix Admin Program File Path Issues

## Problem
The `reopen_program.php` file in `app/views/admin/programs/` had incorrect relative paths for including header and footer files, causing fatal errors.

## Root Cause
- File was using `../layouts/header.php` instead of `../../layouts/header.php`
- File was using `../layouts/footer.php` instead of `../../layouts/footer.php`
- The correct path should go up two levels from `app/views/admin/programs/` to reach `app/views/layouts/`

## Solution
- [x] Fixed header include path from `../layouts/header.php` to `../../layouts/header.php`
- [x] Fixed footer include path from `../layouts/footer.php` to `../../layouts/footer.php`
- [x] Verified consistency with other admin program files

## Files Modified
- `app/views/admin/programs/reopen_program.php` (lines 114 and 190)

## Testing
- [ ] Test the reopen program functionality
- [ ] Verify header and footer load correctly
- [ ] Check that all navigation and styling work properly

## Notes
All other admin program files in the same directory use the correct `../../layouts/` path pattern, confirming this was the right fix. 