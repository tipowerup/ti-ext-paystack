<?php

declare(strict_types=1);

return [
    'text_payment_title' => 'Paystack',
    'text_payment_desc' => 'Accept payments using Paystack',

    'label_title' => 'Title',
    'label_description' => 'Description',
    'label_transaction_mode' => 'Transaction Mode',
    'label_transaction_type' => 'Transaction Type',
    'label_integration_type' => 'Integration Type',
    'label_test_secret_key' => 'Test Secret Key',
    'label_live_secret_key' => 'Live Secret Key',

    'text_live' => 'Live',
    'text_test' => 'Test',
    'text_popup' => 'Popup',
    'text_redirect' => 'Redirect',
    'text_use_another_payment_channel' => 'Use another payment channel',
    'text_delete' => 'Delete',

    'alert_transaction_failed' => 'Sorry, there was an error processing your payment. Please try again later.',
    'alert_payment_profile_not_found' => 'Payment profile not found.',
    'alert_payment_error' => 'Payment Error -> :message',
    'alert_refund_failed' => 'Refund Failed -> :message',
    'alert_payment_successful' => 'Payment successful.',
    'alert_payment_refunded' => 'Payment :transactionId refunded (:amount)',
    'alert_refund_nothing_to_refund' => 'Nothing to refund.',
    'alert_payment_not_settled' => 'Payment not settled.',
    'alert_order_hash_mismatch' => 'Order hash mismatch.',
    'alert_amount_mismatch' => 'Amount mismatch.',
    'alert_refund_amount_should_be_less' => 'Refund amount should be less than total',
];
