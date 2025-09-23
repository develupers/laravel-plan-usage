# Changelog

All notable changes to `laravel-plan-usage` will be documented in this file.

## [Unreleased]

### Added
- Added `plan_prices` table for multiple pricing intervals per plan (monthly, yearly, etc.)
- Added `plan_price_id` column to billable table to track specific pricing selection
- Added `PlanPrice` model for managing multiple price variants per plan
- Added support for multiple billing intervals (monthly, yearly) per plan
- Added `type` field to plans table for visibility/distribution management
- Added plan type constants: `TYPE_PUBLIC`, `TYPE_LEGACY`, `TYPE_PRIVATE`
- Added plan type check methods: `isPublic()`, `isLegacy()`, `isPrivate()`
- Added `isAvailableForPurchase()` method to check if plan is both active and public
- Added plan type scopes: `publicType()`, `legacy()`, `ofType($type)`, `availableForPurchase()`
- Added support for grandfathered/legacy plans that existing customers can keep but new customers cannot purchase
- Added support for private/custom enterprise plans

### Changed
- Updated `add_billable_columns` migration to include `plan_price_id` for tracking specific price variants
- Modified migration to check for existing columns before adding them (idempotent migrations)

### Fixed
- Fixed test failures in QuotaEnforcer and PlanUsageFacade tests by specifying feature types correctly
- Fixed migration compatibility to work with any billable model (users, accounts, teams, etc.)
