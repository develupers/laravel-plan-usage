# Batch Paddle Subscription Reconciliation

## Context

The `subscriptions:reconcile` command in `src/Commands/Subscription/ReconcileSubscriptionsCommand.php` reconciles local subscription state with the billing provider. The Paddle version was recently rewritten to query the Paddle API for each subscription (mirroring the Stripe approach), but this makes one HTTP request per subscription. At scale (1,000+ subscriptions), this is slow and risks hitting Paddle's rate limits.

The Stripe version has the same per-subscription API call pattern but we're fixing the Paddle side first.

## What needs to change

Refactor `reconcilePaddleSubscription()` to use a **batch fetch** approach:

1. **Before the per-billable loop** (around line 107 in `handle()`), when the provider is Paddle, pre-fetch all active/canceled subscriptions from the Paddle API using their list endpoint with pagination (`GET /subscriptions?status=active`, `GET /subscriptions?status=canceled`).

2. **Store the results** in a keyed collection (keyed by subscription ID) so lookups are O(1).

3. **Pass the collection** into `reconcilePaddleSubscription()` so it looks up from the pre-fetched data instead of calling `fetchPaddleSubscription()` per subscription.

4. **Keep `fetchPaddleSubscription()` as a fallback** — if a subscription ID isn't found in the batch (edge case), fall back to the single API call.

5. **Handle pagination** — Paddle's API returns paginated results. Fetch all pages until there are no more.

## Key files

- `src/Commands/Subscription/ReconcileSubscriptionsCommand.php` — the command that needs refactoring
- `src/Providers/Paddle/PaddleProvider.php` — has `makeApiRequest()` that can be referenced for the API call pattern

## Paddle API details

- **List subscriptions**: `GET /subscriptions?status={status}&per_page=200`
- **Pagination**: Response includes `meta.pagination.next` URL for the next page
- **Response structure**: `{ "data": [...subscriptions], "meta": { "pagination": { "next": "https://..." } } }`
- Each subscription object has: `id`, `status`, `scheduled_change` (with `action` field), `current_billing_period.ends_at`

## Approach

1. Add a `fetchAllPaddleSubscriptions()` method that paginates through the Paddle API and returns a collection keyed by subscription ID.
2. Call it once in `handle()` when provider is Paddle, before the `$query->each()` loop.
3. Pass the collection to `reconcilePaddleSubscription()` as an additional parameter.
4. Inside `reconcilePaddleSubscription()`, look up from the collection first. Fall back to `fetchPaddleSubscription()` if not found.
5. The Stripe version remains unchanged (separate task if needed later).

## Verification

- Run `composer test` — all 211 tests should pass
- Run `composer analyse` — should remain at 5 errors (all pre-existing)
- The command should still work with `--dry-run` and `--force` flags
- When Paddle API is unavailable, falls back to local-only reconciliation gracefully
