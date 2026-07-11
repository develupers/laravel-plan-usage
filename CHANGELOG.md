# Changelog

All notable changes to `laravel-plan-usage` will be documented in this file.

## [Unreleased]

### Removed
- **Removed LemonSqueezy support** — LemonSqueezy is sunsetting as an independent platform (folding into Stripe Managed Payments), so the provider class, webhook listener, checkout session, migration stubs, config block, and model columns (`lemon_squeezy_product_id`, `lemon_squeezy_variant_id`, `lemon_squeezy_meter_id`) have been removed, and `'lemon-squeezy'` is no longer a valid `billing.provider` value. Existing databases are untouched (no migration drops the old `lemon_squeezy_*` columns); former LemonSqueezy consumers should move to the Stripe provider

### Added
- Added `supportsTiming()` to the plan-change contract so applications can feature-detect provider timing support (e.g. hide "downgrade at renewal" on Stripe/Paddle) instead of catching exceptions
- Split the lifecycle contract into capabilities: `SubscriptionPlanChangeProvider` (changeSubscription, cancelPendingSubscriptionChange, supportsTiming) and `SubscriptionCancellationProvider` (cancelSubscription, resumeSubscription); `SubscriptionLifecycleProvider` remains as the umbrella extending both, and actions now depend only on the capability they use
- Added `ConfirmPendingPlanChangeAction`: the Polar webhook listener and `subscriptions:reconcile` both funnel pending-change confirmation (refresh / apply / cancel) through it, so the policy lives in exactly one place
- Added **Polar** billing provider support via `danestves/laravel-polar` — one Polar product per `PlanPrice` (`polar_product_id`), checkout, product sync (`plans:push`), reconciliation (`subscriptions:reconcile`), and a durable, out-of-order-safe webhook listener backed by the new `billing_webhook_events` table
- Added `SubscriptionLifecycleProvider` contract for managed plan changes, implemented by Stripe, Paddle (immediate timing), and Polar (immediate + next-period). New surface: `$billable->changePlan()`, `$billable->cancelPendingPlanChange()`, `$billable->pendingPlanChange()`, and `PlanUsage::changePlan()` / `PlanUsage::cancelPendingPlanChange()`
- Added `SubscriptionPlanChange` model + `subscription_plan_changes` table recording every managed plan change (timing, status, effective date), with `SubscriptionPlanChangeScheduled` / `SubscriptionPlanChanged` / `SubscriptionPlanChangeCancelled` events
- Added prorated quota entitlement adjustments on plan changes (`ApplyPlanChangeAction`); non-resetting (lifetime) quota allowances receive the full target on upgrade
- Added lifetime (one-time purchase) plan support on Polar: `order.paid` webhooks assign the plan and quotas for one-time products (orders belonging to a subscription are ignored), and a fully refunded order revokes them

### Changed
- `changePlan()` now rejects a timing the provider does not support **before** creating any change record (previously the record was created, the provider call failed, and the record was marked failed)
- **Behavior change**: with a lifecycle provider bound (Stripe, Paddle, or Polar), `CancelSubscriptionAction::execute($billable, immediately: true)` now clears the local plan and deletes quota rows synchronously instead of waiting for the provider webhook — immediate cancellation revokes entitlements immediately; the webhook remains an idempotent backstop. Period-end cancellation is unchanged
- Provider-specific migrations are now selected per provider at publish time: `plan_prices` gains only the active provider's identifier column, and the lifecycle tables are published only for providers that use them. After switching `BILLING_PROVIDER`, re-run `vendor:publish --tag=plan-usage-migrations` + `migrate`
- `PlanPriceFactory` no longer sets provider identifier columns by default (single-provider consumer schemas don't have the other providers' columns); set them explicitly where needed
- Paddle `resumeSubscription()` now un-cancels via `stopCancelation()` (Cashier Paddle's `resume()` only applies to paused subscriptions)
- Updated `add_billable_columns` migration to include `plan_price_id` for tracking specific price variants
- Modified migration to check for existing columns before adding them (idempotent migrations)

### Added (earlier unreleased work)
- Added `billing_email` column to the Paddle billable migration stub for a per-billable billing identity that falls back to the owner/user email — Paddle allows only one customer per email, so each billable needs its own; pairs with the existing `PaddleProvider::updateCustomerEmail()`
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

### Fixed
- **Behavior change**: Stripe and Paddle webhook listeners now release their dedupe key and rethrow when plan sync fails, so the provider receives a non-2xx response and redelivers — previously a transient failure was swallowed (HTTP 200, no retry) and the pre-set dedupe key blocked even manual redelivery for an hour
- Fixed quota sync reading a stale `plan` relation: `SyncPlanWithBillableAction` and `subscribeToPlan()` now unset the loaded relation after changing plan ids, so quotas always sync from the newly assigned plan instead of a relation cached earlier in the request (the same-plan guard then made the wrong quotas permanent)
- Fixed a race in scheduled quota resets: `ResetExpiredQuotasJob` and `resetExpiredQuotas()` now re-read each quota under a row lock and re-check expiry before resetting, so they can no longer zero usage a concurrent consumer recorded after its own lazy reset
- Fixed read-only quota checks blocking users after renewal: `QuotaEnforcer::canUse()` now evaluates expired quotas against the post-reset projection (used = 0, limit trued up to the current plan) without saving, so `CheckQuota` middleware no longer rejects requests until the first consume performs the lazy reset; `plan-usage:reset-quotas` also no longer skips zero-usage quotas whose stale limits still need truing up
- Fixed lifetime double purchases on Polar: checkout is blocked when the billable already holds the lifetime plan price (one-time purchases create no subscription row, so the `subscribed()` guard could not catch this — and refunding the duplicate would have revoked the surviving order's entitlement); `order.refunded` is also terminal for its order's webhook lineage, so an equal-timestamp paid replay can no longer restore a refunded entitlement
- Fixed Polar period-end cancellations being impossible to un-cancel: `CancelSubscriptionAction::resume()` no longer requires the local grace-period shape (Polar keeps a scheduled cancellation active with `cancel_at_period_end`, so `onGracePeriod()` never passed) — with a lifecycle provider it only rejects fully ended subscriptions and delegates validation to the provider; `resume()` and `cancelAll()` now also serialize behind the shared subscription-state lock
- Made compatibility migration rollbacks non-destructive: the per-provider price-column stubs and `add_lifetime_to_plans_table` no longer drop columns in `down()` (an older combined schema may own them), and the billable-column stubs only drop the package-owned plan-tracking columns, leaving Cashier-owned columns (`stripe_id`, `pm_*`, `trial_ends_at`, `paddle_id`, `billing_email`) in place
- Fixed fresh installs failing with a duplicate `is_lifetime` column: `add_lifetime_to_plans_table` is now `hasColumn`-guarded (it only upgrades databases created before the column moved into `create_plans_table`); its `down()` is intentionally non-destructive since it cannot claim ownership of the column
- Fixed prorated quota limits never truing up: `Quota::reset()` is the single reset primitive and re-derives the limit from the billable's current plan; all lazy reset paths in `QuotaEnforcer` (enforce/increment/reset/resetAll) route through it, and `ResetExpiredQuotasJob` no longer skips zero-usage quotas (their limits still need truing up)
- Fixed routine `subscription.updated` webhooks (renewals, the echo of an applied swap) overwriting prorated mid-cycle limits: `SyncPlanWithBillableAction` now skips when the billable is already on the target plan price — and reads plan ids via Eloquent attributes (`property_exists` alone cannot see model columns, which silently disabled the guard on real models)
- Fixed `SyncPlanWithBillableAction` saving plan ids while swallowing quota-sync failures: assignment and quota sync now run in one transaction and failures rethrow, so a broken sync stays repairable by the next webhook instead of being skipped by the same-plan guard
- Fixed repeated same-period upgrades over-granting credits: proration now uses the current plan's full allowance as the delta reference (1,000 → 5,000 → 15,000 at 50% now yields 8,000, not 9,000), never exceeds the full target, and keeps grandfathered allowances already above the target
- Fixed webhook ordering for events within the same second: `billing_webhook_events` timestamps store microsecond precision and a processed terminal event (revoked/paused) now blocks equal-timestamp non-terminal events from restoring entitlements
- Serialized all subscription-state mutations (plan change, pending-change cancel, immediate cancel, Polar webhooks, Polar reconciliation) behind a shared per-billable lock (`SubscriptionStateLock`, 120s lease to outlive provider API timeouts); reconciliation re-fetches the remote subscription under the lock when a pending change is about to be applied
- Plan-change lifecycle events (`SubscriptionPlanChangeScheduled`, `SubscriptionPlanChanged`, `SubscriptionPlanChangeCancelled`) now implement `ShouldDispatchAfterCommit` so listeners never observe uncommitted state
- Polar order handling now requires the mapped price to be lifetime-interval and ignores orders with a subscription-related `billing_reason`
- Fixed a race where a fast Polar webhook (or a stranded pending record after a crash) applied an immediate plan change with scheduled-change semantics, wiping used credits: pending changes now apply with `resetUsage` derived from their `timing`, and the webhook listener serializes with `ChangeSubscriptionPlanAction` (and with other webhook deliveries for the same billable) via a shared per-billable lock
- Fixed test failures in QuotaEnforcer and PlanUsageFacade tests by specifying feature types correctly
- Fixed migration compatibility to work with any billable model (users, accounts, teams, etc.)
