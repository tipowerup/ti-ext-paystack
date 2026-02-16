+function ($) {
    "use strict"

    var ProcessPaystack = function (element, options) {
        this.$el = $(element)
        this.options = options || {}
        this.$checkoutFormContainer = this.$el.closest('[data-control="checkout"]')
        this.$checkoutForm = this.$checkoutFormContainer.find('form')
        this.$checkoutBtn = $('[data-checkout-control="submit"]')

        this.init()
    }

    ProcessPaystack.prototype.init = function () {
        this.$checkoutForm.on('submitCheckoutForm', $.proxy(this.processPayment, this))
    }

    ProcessPaystack.prototype.processPayment = function (e) {
        var payFromProfile = this.$checkoutForm.find('input[name="pay_from_profile"]:checked').val()
        if (payFromProfile > 0) return

        if (this.options.integrationType === 'redirect') return

        e.preventDefault()
        var self = this

        if (!self.options.orderCreated) {
            self.$checkoutForm.request(self.$checkoutForm.data('handler'))
                .done(function () {
                    self.handlePayment()
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
            url: '/ti_payregister/paystack_initialize_transaction/handle',
            data: {
                'create_payment_profile': createPaymentProfile,
            },
            method: 'POST',
            success: function (authData) {
                var popup = new PaystackPop()
                popup.resumeTransaction(authData.access_code, {
                    onCancel: function () {
                        self.$checkoutBtn.prop('disabled', false)
                    },
                    onSuccess: function (transaction) {
                        self.paymentSuccess(transaction)
                    },
                    onError: function (error) {
                        console.error('An error occurred: ', error)
                        self.$checkoutBtn.prop('disabled', false)
                    }
                })
            },
            error: function (xhr, status, error) {
                console.error('error', error)
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
            url: '/ti_payregister/paystack_payment_successful/handle',
            method: 'POST',
            data: transaction,
            success: function () {
                self.$checkoutForm.unbind('submitCheckoutForm').submit()
            },
            error: function () {
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
        var options = $.extend(true, {}, ProcessPaystack.DEFAULTS, $this.data(), typeof option == 'object' && option)

        return new ProcessPaystack($this, options)
    }

    $.fn.processPaystack.Constructor = ProcessPaystack

    $.fn.processPaystack.noConflict = function () {
        $.fn.processPaystack = old
        return this
    }

    $(document).render(function () {
        $('#paystackForm').processPaystack()
    })
}(window.jQuery)
