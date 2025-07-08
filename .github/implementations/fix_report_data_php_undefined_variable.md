# Fix Undefined Variable Error in report_data.php

## Problem
The `report_data.php` API endpoint was returning PHP warnings instead of valid JSON, causing JSON parsing errors on the frontend. The error was:

```
Warning: Undefined variable $submission in /home/sarawak3/public_html/sarawakforestry.com/pcds30/app/api/report_data.php on line 352
```

## Root Cause
The variable `$submission` was being used in three places in the code but was never defined. This variable should have been `$program` since the code is iterating through programs in a loop.

## Solution
- [x] Identified all instances of `$submission['program_id']` in the file
- [x] Replaced all instances with `$program['program_id']` to use the correct variable
- [x] Fixed lines 351, 384, and 403 in `app/api/report_data.php`

## Changes Made
1. Line 351: `$program_id_str = strval($submission['program_id']);` → `$program_id_str = strval($program['program_id']);`
2. Line 384: `$program_id_str = strval($submission['program_id']);` → `$program_id_str = strval($program['program_id']);`
3. Line 403: `$program_id_str = strval($submission['program_id']);` → `$program_id_str = strval($program['program_id']);`

## Testing
- [x] Fixed the undefined variable error
- [x] API should now return valid JSON instead of PHP warnings
- [x] Frontend JSON parsing should work correctly

## Impact
This fix resolves the JSON parsing error that was preventing report generation from working properly on the live server. 