<?php

declare(strict_types=1);

use Develupers\PlanUsage\Providers\Paddle\PaddleProvider;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Laravel\Paddle\Billable;

/**
 * Concrete billable backed by the shared test_billables table; the provider
 * resolves the Paddle customer through the Cashier Paddle `customer` morph.
 */
class PaddleEmailTestBillable extends Model
{
    use Billable;

    public $timestamps = false;

    protected $table = 'test_billables';

    protected $guarded = [];
}

beforeEach(function () {
    config()->set('cashier.api_key', 'test-api-key');
    config()->set('cashier.sandbox', true);

    if (! Schema::hasTable('customers')) {
        Schema::create('customers', function (Blueprint $table) {
            $table->id();
            $table->morphs('billable');
            $table->string('paddle_id')->unique();
            $table->string('name');
            $table->string('email');
            $table->timestamp('trial_ends_at')->nullable();
            $table->timestamps();
        });
    }

    $this->provider = new PaddleProvider;
});

it('patches the paddle customer and the local row when the email changes', function () {
    $billable = PaddleEmailTestBillable::create([]);
    $billable->customer()->create([
        'paddle_id' => 'ctm_email_1',
        'name' => 'Test',
        'email' => 'old@example.com',
    ]);

    Http::fake([
        '*/customers/ctm_email_1' => Http::response(['data' => ['id' => 'ctm_email_1']]),
    ]);

    $updated = $this->provider->updateCustomerEmail($billable->fresh(), 'new@example.com');

    expect($updated)->toBeTrue()
        ->and($billable->customer()->first()->email)->toBe('new@example.com');

    Http::assertSent(fn ($request) => $request->method() === 'PATCH'
        && str_contains($request->url(), 'customers/ctm_email_1')
        && $request['email'] === 'new@example.com');
});

it('is a no-op when the billable has no paddle customer', function () {
    $billable = PaddleEmailTestBillable::create([]);

    Http::fake();

    expect($this->provider->updateCustomerEmail($billable, 'new@example.com'))->toBeFalse();

    Http::assertNothingSent();
});

it('is a no-op when the email is unchanged', function () {
    $billable = PaddleEmailTestBillable::create([]);
    $billable->customer()->create([
        'paddle_id' => 'ctm_email_2',
        'name' => 'Test',
        'email' => 'same@example.com',
    ]);

    Http::fake();

    expect($this->provider->updateCustomerEmail($billable->fresh(), 'same@example.com'))->toBeFalse();

    Http::assertNothingSent();
});

it('propagates paddle rejections without touching the local row', function () {
    $billable = PaddleEmailTestBillable::create([]);
    $billable->customer()->create([
        'paddle_id' => 'ctm_email_3',
        'name' => 'Test',
        'email' => 'old@example.com',
    ]);

    Http::fake([
        '*/customers/ctm_email_3' => Http::response([
            'error' => ['detail' => 'customer email conflict'],
        ], 409),
    ]);

    expect(fn () => $this->provider->updateCustomerEmail($billable->fresh(), 'taken@example.com'))
        ->toThrow(Exception::class);

    expect($billable->customer()->first()->email)->toBe('old@example.com');
});
