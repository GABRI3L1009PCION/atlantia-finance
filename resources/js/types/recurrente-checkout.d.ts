declare module 'recurrente-checkout' {
    type CheckoutEvent = Record<string, unknown>;

    type CheckoutOptions = {
        url: string;
        onSuccess?: (data: CheckoutEvent) => void;
        onFailure?: (data: CheckoutEvent) => void;
        onPaymentInProgress?: (data: CheckoutEvent) => void;
        development?: boolean;
    };

    const RecurrenteCheckout: {
        load: (options: CheckoutOptions) => void;
    };

    export default RecurrenteCheckout;
}
