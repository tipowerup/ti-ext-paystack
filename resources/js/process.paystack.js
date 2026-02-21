+function ($) {
    "use strict"

    var ProcessPaystack = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutFormContainer = this.$el.closest('[data-control="checkout"]')
        this.$checkoutForm = this.$checkoutFormContainer.find('form')
        this.$checkoutBtn = $('[data-checkout-control="submit"]')
        this.processing = false

        this.init()
    }

    ProcessPaystack.prototype.init = function () {
        this.$checkoutForm.off('submitCheckoutForm.paystack')
        this.$checkoutForm.on('submitCheckoutForm.paystack', $.proxy(this.processPayment, this))
    }

    ProcessPaystack.prototype.processPayment = function (e) {
        var payFromProfile = this.$checkoutForm.find('input[name="pay_from_profile"]:checked').val()
        if (payFromProfile > 0) return

        if (this.options.integrationType === 'redirect') return

        if (this.processing) return
        this.processing = true

        e.preventDefault()
        var self = this

        if (!self.options.orderCreated) {
            self.$checkoutForm.request(self.$checkoutForm.data('handler'))
                .done(function () {
                    self.handlePayment()
                })
                .fail(function () {
                    self.processing = false
                })
        } else {
            self.handlePayment()
        }
    }

    ProcessPaystack.prototype.handlePayment = function () {
        var self = this
        var createPaymentProfile =
            self.$checkoutForm.find('input[name="create_payment_profile"]').is(':checked') ? 1 : 0

        self.$checkoutBtn.prop('disabled', true)

        $.ajax({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
            url: self.options.initializeUrl + '/' + self.options.orderHash,
            data: {
                'create_payment_profile': createPaymentProfile,
            },
            method: 'POST',
            success: function (authData) {
                var popup = new PaystackPop()
                popup.resumeTransaction(authData.access_code, {
                    onCancel: function () {
                        self.processing = false
                        self.$checkoutBtn.prop('disabled', false)
                    },
                    onSuccess: function (transaction) {
                        self.paymentSuccess(transaction)
                    },
                    onError: function (error) {
                        console.error('An error occurred: ', error)
                        self.processing = false
                        self.$checkoutBtn.prop('disabled', false)
                    }
                })
            },
            error: function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'An error occurred while processing your payment.'
                $.ti.flashMessage({class: 'danger', text: message})
                self.processing = false
                self.$checkoutBtn.prop('disabled', false)
            }
        })
    }

    ProcessPaystack.prototype.paymentSuccess = function (transaction) {
        var self = this

        $.ajax({
            headers: {
                'X-CSRF-TOKEN': $('meta[name="csrf-token"]').attr('content'),
            },
            url: self.options.successUrl + '/' + self.options.orderHash,
            method: 'POST',
            data: transaction,
            success: function () {
                self.$checkoutForm.off('submitCheckoutForm.paystack').submit()
            },
            error: function (xhr) {
                var message = xhr.responseJSON && xhr.responseJSON.message
                    ? xhr.responseJSON.message
                    : 'Payment verification failed. Please contact support.'
                $.ti.flashMessage({class: 'danger', text: message})
                self.processing = false
                self.$checkoutBtn.prop('disabled', false)
            }
        })
    }

    ProcessPaystack.DEFAULTS = {}

    // PLUGIN DEFINITION
    // ============================

    var old = $.fn.processPaystack

    $.fn.processPaystack = function (option) {
        var $this = $(this).first()
        var data = $this.data('ti.processPaystack')
        var options = $.extend(true, {}, ProcessPaystack.DEFAULTS, $this.data(), typeof option == 'object' && option)

        if (!data) {
            $this.data('ti.processPaystack', (data = new ProcessPaystack($this, options)))
        } else {
            data.options = options
            data.init()
        }

        return data
    }

    $.fn.processPaystack.Constructor = ProcessPaystack

    $.fn.processPaystack.noConflict = function () {
        $.fn.processPaystack = old
        return this
    }

    $(document).render(function () {
        if ($('#paystackForm').length) {
            $('#paystackForm').processPaystack()
        }
    })
}(window.jQuery)
