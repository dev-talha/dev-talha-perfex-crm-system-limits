# System Limits & Storage Management Module for Perfex CRM

A professional Perfex CRM module for managing package/resource limits, global file storage limits, file usage reporting, and hidden administrator privacy controls.

> Module menu name: **Limit Setup**  
> Report menu name: **System Limit**  
> Latest documented version: **v2.4.3**

---

## Table of Contents

1. [Overview](#overview)
2. [Key Features](#key-features)
3. [User Guide](#user-guide)
   - [Installation](#installation)
   - [Activation](#activation)
   - [Limit Setup](#limit-setup)
   - [Storage Settings](#storage-settings)
   - [System Limit Report](#system-limit-report)
   - [File List](#file-list)
   - [Hidden Administrator Privacy](#hidden-administrator-privacy)
   - [Permissions](#permissions)
4. [Developer Guide](#developer-guide)
   - [Module Structure](#module-structure)
   - [Database Tables](#database-tables)
   - [Hooks and Integration Points](#hooks-and-integration-points)
   - [Storage Limit Flow](#storage-limit-flow)
   - [Resource Limit Flow](#resource-limit-flow)
   - [File List Query Optimization](#file-list-query-optimization)
   - [Hidden Admin Filtering](#hidden-admin-filtering)
   - [Security Notes](#security-notes)
   - [Performance Notes](#performance-notes)
5. [Troubleshooting](#troubleshooting)
6. [Changelog](#changelog)
7. [Support Notes](#support-notes)

---

## Overview

The **System Limits & Storage Management** module allows Perfex CRM administrators to manage global CRM usage limits and file storage limits from one centralized interface.

It is designed for businesses that provide Perfex CRM access to clients while controlling CRM resource usage, storage usage, and internal administrator visibility.

The module helps administrators:

- Set package/resource limits.
- Set global file storage limits.
- Block uploads when storage limit is reached.
- Show clean user-friendly limit messages.
- View uploaded files in a paginated File List.
- Hide internal administrator users from staff lists and filters.
- Keep the interface clean, responsive, and consistent with Perfex CRM.

---

## Key Features

### Resource Limits

Supported resources include:

- Leads
- Staff
- Customers
- Proposals
- Estimates
- Invoices
- Projects
- Tasks
- Media / Attachments

When a configured limit is reached, users see:

```text
Package limit reached. Please upgrade to continue.
```

### Storage Limit Management

The administrator can define a global storage limit such as:

```text
5 MB
5 GB
10 GB
```

When the storage limit is reached, uploads are blocked and only a clean message is shown to the user, not raw JSON.

### File List Report

The File List report displays uploaded files with:

- Serial number
- File name
- File size
- Related module/entity
- Upload/update date
- View action, if the user has permission

The list supports:

- Search
- Pagination
- File size sorting
- Responsive layout
- Long filename shortening

Example long filename display:

```text
verylongfile...report.pdf
```

### Hidden Administrator Privacy

Internal administrator users can be hidden from:

- Staff list
- Direct staff profile access
- Staff/user filters
- Staff dropdowns
- Report filters
- Future module dropdowns where supported by hooks/helpers

Hidden administrators are also excluded from staff limit counts.

---

# User Guide

## Installation

1. Download the module ZIP file.
2. Extract the ZIP file.
3. Upload the module folder to:

```text
modules/system_limits/
```

4. Confirm the final path looks like:

```text
modules/system_limits/system_limits.php
modules/system_limits/controllers/
modules/system_limits/models/
modules/system_limits/views/
```

Do not upload the module as:

```text
modules/system_limits_professional_v2_4_3_plain_message_fix/
```

The folder name must be:

```text
system_limits
```

## Activation

Go to:

```text
Setup > Modules
```

Find the module and click:

```text
Activate
```

After activation, clear browser cache and refresh the admin area.

## Limit Setup

Go to:

```text
Setup > Limit Setup
```

From this page, administrators can configure:

- Resource limits
- Storage limits
- Warning thresholds
- Hidden administrator privacy options
- File view permissions

## Storage Settings

Storage settings allow the administrator to configure:

- Enable/disable storage limit
- Global storage limit value
- Storage unit, such as MB or GB
- Maximum single file size, if available
- Upload restriction behavior

When storage is full, uploads are blocked.

Users should see a clean message instead of raw JSON.

## System Limit Report

Go to:

```text
Reports > System Limit
```

The report page includes:

- Resource Limit Overview
- File List
- Search and filters
- Pagination where available

The old sections below are intentionally removed:

- Staff-wise Storage Usage
- Module-wise Usage
- File Type Breakdown
- Upload Activity by Date Range

## File List

The File List is designed to work similarly to Perfex CRM tables.

It supports:

- Search
- Pagination
- Sorting
- File size order filter
- Separate-tab file viewing

### File Size Sort Options

Available options:

```text
Ascending
Descending
```

### View Files

Users with file view permission can open files in a separate browser tab.

### Delete Files

The delete action has been removed from the File List table.

Delete file permissions are also removed from module role-based permissions.

## Hidden Administrator Privacy

This feature hides selected administrator users from client-facing staff areas.

Recommended use case:

Your company manages a client CRM instance, but you do not want your internal administrator account to appear in the client's staff list or filters.

### Recommended settings

Enable hiding from:

- Staff list
- Direct staff profile access
- Staff filters
- Report filters
- Common dropdowns

Hidden administrators remain able to log in normally, but regular staff users cannot see them in supported staff areas.

## Permissions

The module supports role-based access for report viewing and file viewing where applicable.

The following sensitive permissions are intentionally not available for normal role assignment:

- Manage Storage Settings
- Manage System Record Limits

These are administrator-only actions.

Removed permissions:

- View All Staff Storage Usage
- View Own Storage Usage
- Delete Files

---

# Developer Guide

## Module Structure

Recommended structure:

```text
system_limits/
├── config/
├── controllers/
├── helpers/
├── hooks/
├── language/
├── migrations/
├── models/
├── views/
├── install.php
├── uninstall.php
└── system_limits.php
```

## Database Tables

Depending on installed version and upgrade history, the module may use tables such as:

```text
tblsystem_limits
tblsystem_storage_files
tblsystem_storage_usage_cache
tblsystem_limit_logs
tblsystem_notification_logs
```

### Important Indexing Rules

Indexes must be created safely.

Before creating an index, always check whether the index already exists.

Example problem to avoid:

```text
Duplicate key name 'idx_sl_staff_deleted_size'
```

Recommended pattern:

1. Check `INFORMATION_SCHEMA.STATISTICS`.
2. Create the index only if it is missing.
3. Catch duplicate index exceptions safely if needed.

## Hooks and Integration Points

The module may integrate with Perfex using hooks such as:

```php
hooks()->add_action(...)
hooks()->add_filter(...)
```

Common integration areas:

- Admin menu registration
- Reports menu registration
- Permission registration
- Upload validation
- Staff list filtering
- Dropdown filtering
- Report table rendering

## Storage Limit Flow

Recommended flow:

1. User attempts to upload a file.
2. Module checks current storage usage.
3. Module calculates incoming file size.
4. If usage plus new file exceeds limit:
   - Upload is blocked.
   - Plain message is returned.
5. If allowed:
   - Upload continues.
   - File metadata is tracked.
   - Storage cache is updated.

Expected message when storage limit is reached:

```text
Storage limit exceeded. Limit: 5 MB, current used: 3.47 MB. Please delete files or increase the storage limit.
```

The UI should show the message text only, not JSON.

## Resource Limit Flow

Recommended flow:

1. User attempts to create a resource, such as a lead.
2. Module checks configured resource limit.
3. Module counts current usage.
4. Hidden administrators are excluded from staff count.
5. If limit is exceeded, creation is blocked.
6. User sees:

```text
Package limit reached. Please upgrade to continue.
```

## File List Query Optimization

The File List should be optimized for large datasets.

Recommended practices:

- Use server-side pagination.
- Never load all file rows at once.
- Select only required columns.
- Add indexes on frequently filtered fields.
- Use limit and offset.
- Use file size sorting at SQL level.
- Avoid repeated full storage recalculation during page load.

Recommended indexed columns:

```text
file_size
uploaded_at
updated_at
module_name
staff_id
is_deleted
```

## Hidden Admin Filtering

Hidden administrator filtering should apply to:

- Staff list queries
- Staff dropdowns
- Staff filters
- Report filters
- Resource count calculations
- Direct staff profile access checks

Recommended behavior:

```text
Hidden admin user: can see themselves and normal staff if allowed.
Normal staff user: cannot see hidden administrator users.
```

Avoid modifying Perfex core files where possible. Use hooks, filters, helpers, or minimal view overrides.

## Security Notes

Production-safe requirements:

- Use permission checks before report access.
- Use permission checks before file view access.
- Sanitize all GET/POST inputs.
- Validate file paths before opening files.
- Prevent path traversal.
- Do not expose server absolute paths.
- Use CSRF protection for POST actions.
- Avoid raw SQL unless carefully escaped.
- Do not show hidden administrator information to normal users.

## Performance Notes

For better performance:

- Use cached storage usage totals.
- Use indexes for report queries.
- Use AJAX pagination for large tables.
- Avoid heavy calculations during every page load.
- Run full storage scan manually or by cron if needed.
- Avoid loading staff-wise/module-wise/file-type analytics unless required.

---

# Troubleshooting

## HTTP ERROR 500 on Module Activation

Check Perfex logs:

```text
application/logs/log-YYYY-MM-DD.php
```

Look for:

```text
Severity: error
Exception:
Fatal error
Duplicate key name
Column is ambiguous
```

### Duplicate Index Error

Example:

```text
Duplicate key name 'idx_sl_staff_deleted_size'
```

Cause:

The module attempted to create an index that already exists.

Fix:

Use a module version where index creation checks `INFORMATION_SCHEMA` before creating indexes.

## Ambiguous Staff ID Error

Example:

```text
Column 'staffid' in SELECT is ambiguous
```

Cause:

A query joins multiple tables that contain `staffid`, but the selected column was not prefixed with the table name or alias.

Fix:

Use fully qualified column names such as:

```sql
tblstaff.staffid
```

or query aliases.

## Storage Settings Not Saving

Check:

- Form action URL
- CSRF token
- Option names
- Unit conversion logic
- Database option update method

## Raw JSON Showing Instead of Message

Incorrect behavior:

```json
{"success":false,"message":"Package limit reached. Please upgrade to continue."}
```

Correct behavior:

```text
Package limit reached. Please upgrade to continue.
```

Fix response handling for AJAX and non-AJAX requests separately.

---

# Changelog

## v2.4.3

- Fixed raw JSON display for storage limit message.
- Fixed raw JSON display for lead limit message.
- Both now show plain text messages in the UI.

## v2.4.2

- Updated package limit message.
- Added serial number column in File List.
- Fixed Storage Settings save issue.
- Removed security alert section from settings page.

## v2.4.1

- Removed Staff/Module and File Type filters from report page.
- Added file size sort filter.
- Removed delete action from File List.
- Removed Uploaded By column.

## v2.4.0

- Renamed Largest Files to File List.
- Added File List permissions and view support.
- Removed staff-wise/module-wise/file-type/activity sections.
- Renamed setup menu to Limit Setup.

## v2.3.x

- Fixed duplicate index activation issues.
- Fixed ambiguous staff ID report issue.
- Improved hidden administrator filtering.
- Improved responsive report pages.

---

# Support Notes

When reporting a bug, provide:

1. Module version.
2. Perfex CRM version.
3. PHP version.
4. MySQL/MariaDB version.
5. Exact URL where the issue happens.
6. Screenshot if UI-related.
7. Latest error log lines from:

```text
application/logs/log-YYYY-MM-DD.php
```

Recommended log details:

```text
Severity: error
Exception message
File path
Line number
```

---

## License / Usage

This README is prepared for a custom Perfex CRM module project. Adjust license and distribution terms according to your own business policy before publishing publicly on GitHub.
