<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Http;
use Tipowerup\Paystack\Classes\PaystackApi;

it('initializes a transaction successfully', function (): void {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => true,
            'message' => 'Authorization URL created',
            'data' => [
                'authorization_url' => 'https://checkout.paystack.com/test',
                'access_code' => 'test_access_code',
                'reference' => 'test_ref',
            ],
        ]),
    ]);

    $api = new PaystackApi('sk_test_123');
    $result = $api->initializeTransaction([
        'email' => 'test@example.com',
        'amount' => 10000,
    ]);

    expect($result['status'])->toBeTrue()
        ->and($result['data']['access_code'])->toBe('test_access_code');
});

it('throws exception on initialize transaction failure', function (): void {
    Http::fake([
        'api.paystack.co/transaction/initialize' => Http::response([
            'status' => false,
            'message' => 'Invalid key',
        ], 401),
    ]);

    $api = new PaystackApi('sk_test_123');
    $api->initializeTransaction([
        'email' => 'test@example.com',
        'amount' => 10000,
    ]);
})->throws(Exception::class, 'Invalid key');

it('verifies a transaction successfully', function (): void {
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => true,
            'message' => 'Verification successful',
            'data' => [
                'status' => 'success',
                'amount' => 10000,
                'reference' => 'test_ref',
            ],
        ]),
    ]);

    $api = new PaystackApi('sk_test_123');
    $result = $api->verifyTransaction('test_ref');

    expect($result['status'])->toBeTrue()
        ->and($result['data']['status'])->toBe('success');
});

it('throws exception on verify transaction failure', function (): void {
    Http::fake([
        'api.paystack.co/transaction/verify/*' => Http::response([
            'status' => false,
            'message' => 'Transaction not found',
        ], 404),
    ]);

    $api = new PaystackApi('sk_test_123');
    $api->verifyTransaction('invalid_ref');
})->throws(Exception::class, 'Transaction not found');

it('charges authorization successfully', function (): void {
    Http::fake([
        'api.paystack.co/transaction/charge_authorization' => Http::response([
            'status' => true,
            'message' => 'Charge attempted',
            'data' => [
                'status' => 'success',
                'amount' => 10000,
            ],
        ]),
    ]);

    $api = new PaystackApi('sk_test_123');
    $result = $api->chargeAuthorization([
        'email' => 'test@example.com',
        'amount' => 10000,
        'authorization_code' => 'AUTH_test123',
    ]);

    expect($result['status'])->toBeTrue()
        ->and($result['data']['status'])->toBe('success');
});

it('throws exception on charge authorization failure', function (): void {
    Http::fake([
        'api.paystack.co/transaction/charge_authorization' => Http::response([
            'status' => false,
            'message' => 'Invalid authorization code',
        ], 400),
    ]);

    $api = new PaystackApi('sk_test_123');
    $api->chargeAuthorization([
        'email' => 'test@example.com',
        'amount' => 10000,
        'authorization_code' => 'invalid',
    ]);
})->throws(Exception::class, 'Invalid authorization code');

it('creates a refund successfully', function (): void {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => true,
            'message' => 'Refund has been queued for processing',
            'data' => [
                'transaction' => 'txn_test123',
                'amount' => 5000,
            ],
        ]),
    ]);

    $api = new PaystackApi('sk_test_123');
    $result = $api->createRefund('txn_test123', 5000);

    expect($result['status'])->toBeTrue()
        ->and($result['message'])->toBe('Refund has been queued for processing');
});

it('throws exception on refund failure', function (): void {
    Http::fake([
        'api.paystack.co/refund' => Http::response([
            'status' => false,
            'message' => 'Transaction has already been refunded',
        ], 400),
    ]);

    $api = new PaystackApi('sk_test_123');
    $api->createRefund('txn_test123', 5000);
})->throws(Exception::class, 'Transaction has already been refunded');
