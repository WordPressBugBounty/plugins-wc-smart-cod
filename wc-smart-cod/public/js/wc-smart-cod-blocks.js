/* Native Store API and public WooCommerce data-store actions. No Classic checkout emulation. */
(function () {
    'use strict';
    const wc = window.wc, wp = window.wp;
    if (!wc || !wp || !wc.wcBlocksRegistry || !wc.blocksCheckout || !wc.wcBlocksData) return;
    const { cartStore, paymentStore, checkoutStore, validationStore } = wc.wcBlocksData;
    const { data, element, plugins } = wp;
    const { __ } = wp.i18n;
    const namespace = 'wc-smart-cod';
    const protection = (cart) => (cart && cart.extensions && cart.extensions[namespace]) || {};
    const current = () => protection(data.select(cartStore).getCartData());
    const settings = window.wscBlocksSettings || {};
    let nonce = settings.storeApiNonce || '';
    const callbacks = { cod: ({cart={}}={}) => protection(cart).cod_available === true };
    wc.wcBlocksRegistry.registerPaymentMethodExtensionCallbacks(namespace, callbacks);
    let pending = null, running = false, last = '', failed = false, lastError = '';
    const errorId = 'wc-smart-cod-update';
    const customerUpdating = () => {
        const cart = data.select(cartStore);
        return ['isCustomerDataUpdating','isCartDataStale','isShippingRateBeingSelected',
            'hasPendingItemsOperations','isApplyingCoupon','isRemovingCoupon'].some(selector =>
            typeof cart[selector] === 'function' && cart[selector]());
    };
    // Only inputs missing from Woo's automatic Store API refresh belong in this job.
    // Native item/coupon/shipping updates already return a fresh server protection policy.
    function checkoutInputs() {
        const store = data.select(cartStore), cart = store.getCartData(), customer = store.getCustomerData();
        const address = (value = {}) => Object.fromEntries(Object.keys(value).sort()
            .filter(key => ['country','state','city','postcode'].includes(key) || key.includes('/'))
            .map(key => [key, value[key]]));
        const needsShipping = typeof store.getNeedsShipping === 'function' ? store.getNeedsShipping() : cart.needsShipping;
        return {customer:{billing_address:address(customer.billingAddress),
            ...(needsShipping === false ? {} : {shipping_address:address(customer.shippingAddress)})}};
    }
    async function updateNativeCart(job) {
        const payload = job;
        const response = await wp.apiFetch({path:'/wc/store/v1/cart/extensions',method:'POST',
            data:{namespace,data:payload},headers:{Nonce:nonce},parse:false});
        const cart = await response.json();
        if (!cart || !Array.isArray(cart.items) || !cart.totals || typeof protection(cart).cod_available !== 'boolean') {
            throw new Error('Invalid native cart response');
        }
        nonce = response.headers.get('Nonce') || nonce;
        // This public Woo action consumes the native cart but deliberately omits addresses.
        // The native customer store owns edits; payment updates must never replace them.
        data.dispatch(cartStore).receiveCartContents(cart);
    }
    function validate() {
        const method = data.select(paymentStore).getActivePaymentMethod();
        const state = current();
        const error = method === 'cod' && (failed || state.cod_available === false)
            ? __('Please reload the page and try again.', 'woocommerce') : '';
        if (lastError === error) return;
        lastError = error;
        if (error) data.dispatch(validationStore).setValidationErrors({[errorId]:{message:error,hidden:true}});
        else data.dispatch(validationStore).clearValidationError(errorId);
    }
    async function drain() {
        if (running || customerUpdating()) return;
        running = true;
        try {
            await data.dispatch(checkoutStore).disableCheckoutFor(async () => {
                while (pending && !customerUpdating()) {
                    const job = pending; pending = null;
                    try {
                        await updateNativeCart(job);
                        failed = false;
                    } catch (error) { failed = true; }
                    validate();
                }
            });
        } finally { running = false; if (pending && !customerUpdating()) drain(); }
    }
    function changed() {
        const customer = data.select(cartStore).getCustomerData().billingAddress || {};
        const snapshot = {payment_method:data.select(paymentStore).getActivePaymentMethod() || '',
            email:customer.email || '', phone:customer.phone || '', ...checkoutInputs()};
        if (snapshot.email && !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(snapshot.email)) snapshot.email = '';
        const key = JSON.stringify(snapshot);
        if (key !== last) { last = key; pending = snapshot; }
        if (pending && !running && !customerUpdating()) Promise.resolve().then(drain);
        validate();
    }
    data.subscribe(changed);
    changed();
    const h = element.createElement;
    function Summary() {
        const state = data.useSelect(() => current(), []);
        return h('div', {className:'wc-smart-cod-blocks-summary'},
            state.message && h('p',{role:'status'},state.message)
        );
    }
    if (plugins && wc.blocksCheckout.ExperimentalOrderMeta) plugins.registerPlugin('wc-smart-cod-summary',{
        scope:'woocommerce-checkout',
        render:()=>h(wc.blocksCheckout.ExperimentalOrderMeta,null,h(Summary)),
    });
}());

