# Changelog

All notable changes to `laravel-plan-usage` will be documented in this file.

## [Unreleased]

### Added
- Added `type` field to plans table for visibility/distribution management
- Added plan type constants: `TYPE_PUBLIC`, `TYPE_LEGACY`, `TYPE_PRIVATE`
- Added plan type check methods: `isPublic()`, `isLegacy()`, `isPrivate()`
- Added `isAvailableForPurchase()` method to check if plan is both active and public
- Added plan type scopes: `publicType()`, `legacy()`, `ofType($type)`, `availableForPurchase()`
- Added support for grandfathered/legacy plans that existing customers can keep but new customers cannot purchase
- Added support for private/custom enterprise plans

### Fixed
- Fixed test failures in QuotaEnforcer and PlanUsageFacade tests by specifying feature types correctly
