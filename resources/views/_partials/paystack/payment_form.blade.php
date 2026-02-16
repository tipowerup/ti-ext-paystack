@php($paymentProfile = $order->customer && $paymentMethod->paymentProfileExists($order->customer)
    ? $paymentMethod->findPaymentProfile($order->customer)
    : null
)
<div id="paystackForm" class="payment-form w-100"
     data-order-created="{{ (bool)$order->order_id }}"
     data-integration-type="{{ $paymentMethod->getIntegrationType() }}"
>
    @if ($paymentProfile)
        <div class="form-group">
            <div>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="savedCard" name="pay_from_profile" value="1" checked>
                    <label class="d-flex justify-content-between" for="savedCard">
                        <div>
                            @if($paymentMethod->isCardIconSupported($paymentProfile->card_brand))
                                <i class="fab fa-fw fa-cc-{{ $paymentProfile->card_brand }}"></i>
                            @else
                                <i class="fas fa-credit-card"></i>
                            @endif
                            &nbsp;&nbsp;
                            <b>&bull;&bull;&bull;&bull;&nbsp;&bull;&bull;&bull;&bull;&nbsp;&bull;&bull;&bull;&bull;&nbsp;{{ $paymentProfile->card_last4 }}</b>
                        </div>
                        <button
                            class="btn btn-link btn-sm text-danger"
                            data-checkout-control="delete-payment-profile"
                            data-payment-code="{{ $paymentMethod->code }}"
                            title="@lang('tipowerup.paystack::default.text_delete')"
                        >
                            <i class="fa fa-trash"></i>
                        </button>
                    </label>
                </div>
                <div class="form-check">
                    <input type="radio" class="form-check-input" id="anotherPaymentChannel" name="pay_from_profile" value="0">
                    <label for="anotherPaymentChannel">
                        @lang('tipowerup.paystack::default.text_use_another_payment_channel')
                    </label>
                </div>
            </div>
        </div>
    @else
        @if ($paymentMethod->supportsPaymentProfiles() && $order->customer)
            <div class="form-group">
                <div class="form-check mt-2">
                    <input
                        id="save-customer-profile"
                        type="checkbox"
                        class="form-check-input"
                        name="create_payment_profile"
                        value="1"
                    >
                    <label
                        class="form-check-label"
                        for="save-customer-profile"
                    >@lang('igniter.payregister::default.text_save_card_profile')</label>
                </div>
            </div>
        @endif
    @endif
</div>
