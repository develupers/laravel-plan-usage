Analyze the database schema and document each table and notable objects.

**IMPORTANT: This command will overwrite the existing schema.md file with an enhanced version.**

Steps:
1. Run `php artisan db:schema` to generate the latest schema dump
2. Read the generated `docs/project/schema.md` file
3. Analyze each table's structure, columns, and relationships
4. **Replace the content of `docs/project/schema.md`** with an enhanced version that includes:

**Enhanced Schema Format:**
```markdown
# Database Schema

**Generated on:** [timestamp]
**Connection:** [connection]
**Driver:** [driver]  
**Database:** [database]

## Table of Contents
- [Authentication & User Management](#authentication--user-management)
- [Queue & Job Processing](#queue--job-processing)
- [Application Monitoring](#application-monitoring)
- [System & Utility Tables](#system--utility-tables)
- [Application Tables](#application-tables)

## Authentication & User Management

### users
**Description:** Core user accounts table storing authentication credentials and basic profile information. Central to the authentication system with Laravel timestamps.

#### Columns
[existing column table]

#### Indexes  
[existing index table]

#### Notable Features:
- Uses Laravel authentication conventions
- Includes remember_token for "Remember Me" functionality
- Standard created_at/updated_at timestamps

---

### [other tables following same pattern]
```

**Analysis Guidelines:**
For each table, identify and describe:
- **Purpose**: What data does this table store? (1-2 sentences)
- **Laravel Patterns**: Timestamps, soft deletes, polymorphic relations, etc.
- **Relationships**: How does it connect to other tables?
- **Notable Columns**: Special fields like JSON, UUIDs, etc.
- **Performance Features**: Important indexes, constraints

**Quick Reference Section** (add at the end):
- Tables with foreign keys
- Tables with JSON/large text columns  
- Laravel convention usage (timestamps, soft deletes, etc.)

The goal is to make `docs/project/schema.md` a comprehensive, developer-friendly reference that explains not just the structure but the purpose and patterns of each table.
