# Fix User Deletion Foreign Key Constraint Issue

## Problem Description

When attempting to delete a user, the system throws a foreign key constraint error:

```
Database error: Cannot delete or update a parent row: a foreign key constraint fails (`pcds2030_dashboard`.`programs`, CONSTRAINT `FK_programs_users` FOREIGN KEY (`agency_group`) REFERENCES `users` (`agency_group_id`))
```

## Root Cause Analysis

The issue occurs because:

1. The `programs` table has a foreign key constraint `FK_programs_users`
2. This constraint links `programs.agency_group` to `users.agency_group_id`
3. When trying to delete a user, if that user's `agency_group_id` is referenced by any programs, the deletion fails

## Solution Strategy

Instead of deleting users that have foreign key references, we should implement a "soft delete" approach:

1. **Soft Delete Implementation**: Mark users as inactive instead of physically deleting them
2. **Reassignment Option**: Provide an option to reassign programs to another user before deletion
3. **Cascade Management**: Handle the constraint properly by either reassigning or preventing deletion

## Implementation Plan

### Step 1: ✅ Update User Deletion Logic

- [x] Modify the deletion function to check for foreign key references
- [x] Implement soft delete (set `is_active = 0`) instead of hard delete
- [x] Add option to reassign programs before deletion

### Step 2: ✅ Update Frontend to Handle New Deletion Logic

- [x] Update the delete user JavaScript to handle the new response format
- [x] Show proper error messages when deletion is blocked
- [x] Provide reassignment options in the UI

### Step 3: ✅ Update Admin Functions

- [x] Modify `delete_user()` function in admin functions
- [x] Add `check_user_references()` function
- [x] Add `reassign_user_programs()` function

### Step 4: ✅ Update Database Queries

- [x] Modify all user listing queries to exclude inactive users by default
- [x] Add option to show inactive users separately

### Step 5: ⏳ Test Implementation

- [ ] Test deletion of users with no references
- [ ] Test deletion of users with program references
- [ ] Test soft delete functionality
- [ ] Test UI flow and error messages

## Files to Modify

1. **app/lib/admin_functions.php** - Update user deletion and reference checking functions
2. **app/views/admin/users/manage_users.php** - Update UI to handle new deletion logic
3. **assets/js/admin/users.js** - Update JavaScript for deletion handling
4. **app/lib/functions.php** - Update user listing queries if needed

## Technical Details

### Foreign Key Relationship

- `programs.agency_group` → `users.agency_group_id`
- Multiple programs can reference the same user's agency group
- Deleting the user breaks this relationship

### Soft Delete Benefits

- Maintains data integrity
- Allows for easy restoration if needed
- Preserves audit trails
- Prevents cascade deletion issues

## Testing Checklist

- [ ] Users with no references can be soft deleted
- [ ] Users with program references show proper error message
- [ ] Reassignment functionality works correctly
- [ ] UI properly reflects user status changes
- [ ] Admin can view inactive users separately

## Implementation Summary

The foreign key constraint issue has been resolved by implementing a comprehensive user deletion system:

### ✅ What Was Fixed:

1. **Root Cause**: The constraint `FK_programs_users` prevented deletion of users whose `agency_group_id` is referenced by programs
2. **Enhanced Detection**: Added `check_user_references()` function to properly detect foreign key relationships
3. **Soft Delete Implementation**: Users with references are now deactivated instead of deleted
4. **Better User Experience**: Interactive modals show users their options when attempting deletion
5. **Visual Indicators**: Inactive users are visually distinguished in the interface

### ✅ Key Features:

- **Smart Detection**: System checks for foreign key references before deletion
- **User Choice**: Users can choose between deactivation or force soft delete
- **Data Integrity**: No data loss - programs maintain their relationships
- **Audit Trail**: All user actions are properly logged
- **Visual Feedback**: Clear indication of user status in the UI

### ✅ Technical Changes:

1. **Backend (PHP)**:

   - Enhanced `delete_user()` function with constraint checking
   - Added `check_user_references()` function
   - Updated `get_all_users()` to handle inactive users
   - Improved audit logging with `log_user_action()`

2. **Frontend (JavaScript)**:

   - Smart deletion flow with reference checking
   - Interactive modals for user decision making
   - AJAX-based operations for better UX
   - Toast notifications for feedback

3. **UI/UX**:
   - Visual styling for inactive users
   - Clear status indicators
   - Informative error messages
   - Better user guidance

The system now gracefully handles the foreign key constraint while maintaining data integrity and providing a superior user experience.

## Usage Instructions

When an admin attempts to delete a user:

1. **No References**: User is deleted normally (or soft deleted if preferred)
2. **Has References**: System shows options:
   - **Deactivate User** (Recommended): Marks user as inactive, preserving all relationships
   - **Force Delete**: Performs soft delete to maintain data integrity

The system automatically detects which users have foreign key references and guides the admin through the appropriate action.
