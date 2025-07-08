# Downgrade PHP Compatibility to 7.0 - Implementation Plan

## Overview

Downgrade all report module functions from PHP 7.4+ requirements to PHP 7.0 compatibility while maintaining full functionality.

## Current Issues Requiring Downgrade

### PHP 7.4+ Features to Replace

1. **Null Coalescing Assignment (`??=`)** - Not used extensively but may be present
2. **Arrow Functions** - Replace with traditional anonymous functions
3. **Array Spread Operator in Arrays** - Use `array_merge()` instead
4. **Typed Properties** - Remove or replace with docblocks
5. **Numeric Literal Separators** - Remove underscores from numbers

### PHP 7.1+ Features to Replace

1. **Nullable Type Declarations** - Remove `?type` syntax
2. **Array Destructuring with Keys** - Use traditional array access
3. **Multi-catch Exception Handling** - Use separate catch blocks

## Files to Modify

### API Endpoints

- [x] `app/api/generate_report.php`
- [x] `app/api/report_data.php`
- [x] `app/api/save_report.php`
- [x] `app/api/delete_report.php`
- [ ] `app/api/get_period_programs.php`
- [ ] `app/api/get_program_targets.php`

### View Files

- [ ] `app/views/admin/reports/generate_reports.php`
- [x] `app/views/admin/ajax/recent_reports_paginated.php`
- [x] `app/views/admin/ajax/recent_reports_table.php`
- [x] `app/views/admin/ajax/recent_reports_table_new.php`
- [ ] `app/views/agency/reports/view_reports.php`
- [x] `app/views/agency/reports/public_reports.php`

### Library Files

- [ ] Check and update any report-related library functions

## Specific Changes Required

### 1. Parameter Handling Patterns

**Current (PHP 7.4+):**

```php
$page = $_GET['page'] ?? 1;
$search = trim($_GET['search'] ?? '');
```

**Downgraded (PHP 7.0):**

```php
$page = isset($_GET['page']) ? $_GET['page'] : 1;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';
```

### 2. Array Operations

**Current (PHP 7.4+):**

```php
$result = [...$array1, ...$array2];
```

**Downgraded (PHP 7.0):**

```php
$result = array_merge($array1, $array2);
```

### 3. Type Declarations

**Current (PHP 7.4+):**

```php
function processData(?array $data): ?string {
    // code
}
```

**Downgraded (PHP 7.0):**

```php
/**
 * @param array|null $data
 * @return string|null
 */
function processData($data) {
    // code
}
```

### 4. Exception Handling

**Current (PHP 7.1+):**

```php
try {
    // code
} catch (TypeError | ValueError $e) {
    // handle
}
```

**Downgraded (PHP 7.0):**

```php
try {
    // code
} catch (TypeError $e) {
    // handle
} catch (ValueError $e) {
    // handle
}
```

## Testing Strategy

### 1. PHP Version Testing

- [ ] Test on PHP 7.0 environment
- [ ] Test on PHP 7.1 to ensure backward compatibility
- [ ] Test on current PHP 7.4+ to ensure no regression

### 2. Functionality Testing

- [ ] Report generation functionality
- [ ] File upload/download operations
- [ ] Database operations
- [ ] Error handling
- [ ] API responses

### 3. Performance Testing

- [ ] Memory usage comparison
- [ ] Execution time comparison
- [ ] Large dataset handling

## Implementation Steps

### Phase 1: Analysis and Preparation

- [ ] Scan all files for PHP 7.1+ specific syntax
- [ ] Identify all nullable type declarations
- [ ] List all arrow functions usage
- [ ] Document current error handling patterns

### Phase 2: Core API Files

- [ ] Update `generate_report.php`
- [ ] Update `report_data.php`
- [ ] Update `save_report.php`
- [ ] Update `delete_report.php`
- [ ] Test each file individually

### Phase 3: Supporting API Files

- [ ] Update `get_period_programs.php`
- [ ] Update `get_program_targets.php`
- [ ] Test program retrieval functionality

### Phase 4: View Files

- [ ] Update admin view files
- [ ] Update agency view files
- [ ] Update AJAX endpoints
- [ ] Test UI functionality

### Phase 5: Integration Testing

- [ ] Full report generation workflow
- [ ] Multi-user testing
- [ ] Error scenario testing
- [ ] Performance verification

## Backup Strategy

### Before Changes

- [ ] Create backup of current working system
- [ ] Document current PHP version requirements
- [ ] Create rollback procedure

### During Changes

- [ ] Commit changes incrementally
- [ ] Test each component after modification
- [ ] Document any issues encountered

## Risk Mitigation

### Potential Issues

1. **Performance degradation** from replacing optimized PHP 7.4+ features
2. **Code verbosity** from replacing concise modern syntax
3. **Error handling complexity** from expanded exception handling

### Mitigation Strategies

1. **Benchmark critical paths** before and after changes
2. **Maintain code readability** with proper comments
3. **Implement comprehensive error logging**

## Success Criteria

### Functional Requirements

- [ ] All report generation features work on PHP 7.0
- [ ] No functionality regression
- [ ] All API endpoints respond correctly
- [ ] File operations work properly

### Performance Requirements

- [ ] No significant performance degradation (< 10%)
- [ ] Memory usage remains within acceptable limits
- [ ] Response times remain acceptable

### Compatibility Requirements

- [ ] Works on PHP 7.0+
- [ ] Compatible with typical cPanel hosting
- [ ] No breaking changes for existing data

## Documentation Updates

### Code Documentation

- [ ] Update function docblocks with correct type hints
- [ ] Add comments explaining workarounds for PHP 7.0
- [ ] Update inline documentation

### System Documentation

- [ ] Update compatibility analysis document
- [ ] Update hosting requirements
- [ ] Update developer setup instructions

## Completion Checklist

- [ ] All files updated and tested
- [ ] PHP 7.0 compatibility verified
- [ ] Performance benchmarks acceptable
- [ ] Documentation updated
- [ ] Backup and rollback procedures tested
- [ ] Integration testing completed
- [ ] User acceptance testing passed
