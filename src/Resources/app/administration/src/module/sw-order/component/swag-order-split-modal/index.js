import template from './swag-order-split-modal.html.twig';
import './swag-order-split-modal.scss';

const { Criteria } = Shopware.Data;

/**
 * Modal to split a placed order across multiple delivery addresses from the
 * admin. Mirrors the storefront split: per (shippable) line item, route
 * quantities to the customer's saved addresses, and choose a shipping method
 * per address. On save it calls the plugin's admin-API endpoint, which
 * rebuilds the order's deliveries.
 */
Shopware.Component.register('swag-order-split-modal', {
    template,

    inject: ['repositoryFactory', 'syncService'],

    mixins: ['notification'],

    props: {
        order: {
            type: Object,
            required: true,
        },
    },

    data() {
        return {
            isLoading: true,
            isSaving: false,
            addresses: [],
            shippingMethods: [],
            // rows[lineItemId] = [{ addressId, quantity }]
            rows: {},
            // methodChoices[addressId] = shippingMethodId
            methodChoices: {},
        };
    },

    computed: {
        customerAddressRepository() {
            return this.repositoryFactory.create('customer_address');
        },

        shippingMethodRepository() {
            return this.repositoryFactory.create('shipping_method');
        },

        deliverableItems() {
            return (this.order.lineItems ?? []).filter((item) => item.type === 'product');
        },

        addressOptions() {
            return this.addresses.map((address) => ({
                value: address.id,
                label: this.formatAddress(address),
            }));
        },

        methodOptions() {
            return this.shippingMethods.map((method) => ({
                value: method.id,
                label: method.translated?.name ?? method.name,
            }));
        },

        distinctAddressIds() {
            const ids = [];
            Object.values(this.rows).forEach((itemRows) => {
                itemRows.forEach((row) => {
                    if (row.addressId && !ids.includes(row.addressId)) {
                        ids.push(row.addressId);
                    }
                });
            });
            return ids;
        },

        isValid() {
            return this.deliverableItems.every((item) => {
                const itemRows = this.rows[item.id] ?? [];
                const used = new Set();
                let sum = 0;
                for (const row of itemRows) {
                    if (!row.addressId) {
                        return false;
                    }
                    if (used.has(row.addressId)) {
                        return false;
                    }
                    used.add(row.addressId);
                    sum += Number(row.quantity) || 0;
                }
                return sum === item.quantity;
            });
        },
    },

    created() {
        this.createdComponent();
    },

    methods: {
        async createdComponent() {
            this.isLoading = true;
            try {
                await Promise.all([this.loadAddresses(), this.loadShippingMethods()]);
                this.initRows();
            } finally {
                this.isLoading = false;
            }
        },

        loadAddresses() {
            const customerId = this.order.orderCustomer?.customerId;
            if (!customerId) {
                return Promise.resolve();
            }
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('customerId', customerId));
            criteria.addAssociation('country');
            criteria.addSorting(Criteria.sort('createdAt', 'DESC'));

            return this.customerAddressRepository.search(criteria, Shopware.Context.api).then((result) => {
                this.addresses = result;
            });
        },

        loadShippingMethods() {
            const criteria = new Criteria(1, 100);
            criteria.addFilter(Criteria.equals('active', true));
            criteria.addSorting(Criteria.sort('name', 'ASC'));

            return this.shippingMethodRepository.search(criteria, Shopware.Context.api).then((result) => {
                this.shippingMethods = result;
            });
        },

        initRows() {
            const rows = {};
            const defaultAddress = this.addresses.length ? this.addresses[0].id : null;
            this.deliverableItems.forEach((item) => {
                rows[item.id] = [{ addressId: defaultAddress, quantity: item.quantity }];
            });
            this.rows = rows;
        },

        addRow(lineItemId) {
            this.rows[lineItemId].push({ addressId: null, quantity: 1 });
        },

        removeRow(lineItemId, index) {
            if (this.rows[lineItemId].length > 1) {
                this.rows[lineItemId].splice(index, 1);
            }
        },

        itemError(item) {
            const itemRows = this.rows[item.id] ?? [];
            const used = new Set();
            let sum = 0;
            let missing = false;
            let duplicate = false;

            itemRows.forEach((row) => {
                sum += Number(row.quantity) || 0;
                if (!row.addressId) {
                    missing = true;
                } else if (used.has(row.addressId)) {
                    duplicate = true;
                } else {
                    used.add(row.addressId);
                }
            });

            if (duplicate) {
                return this.$tc('swag-order-splitting-split-modal.errorDuplicate');
            }
            if (missing) {
                return this.$tc('swag-order-splitting-split-modal.errorMissingAddress');
            }
            if (sum !== item.quantity) {
                return this.$t('swag-order-splitting-split-modal.errorSum', { sum, total: item.quantity });
            }
            return null;
        },

        addressLabelById(addressId) {
            const address = this.addresses.find((a) => a.id === addressId);
            return address ? this.formatAddress(address) : addressId;
        },

        formatAddress(address) {
            const country = address.country ? (address.country.translated?.name ?? address.country.name) : '';
            return [
                `${address.firstName ?? ''} ${address.lastName ?? ''}`.trim(),
                address.street,
                `${address.zipcode ?? ''} ${address.city ?? ''}`.trim(),
                country,
            ].filter(Boolean).join(', ');
        },

        buildPayload() {
            const assignment = {};
            this.deliverableItems.forEach((item) => {
                const perAddress = {};
                (this.rows[item.id] ?? []).forEach((row) => {
                    const qty = Number(row.quantity) || 0;
                    if (row.addressId && qty > 0) {
                        perAddress[row.addressId] = (perAddress[row.addressId] ?? 0) + qty;
                    }
                });
                assignment[item.id] = perAddress;
            });

            const method = {};
            this.distinctAddressIds.forEach((addressId) => {
                if (this.methodChoices[addressId]) {
                    method[addressId] = this.methodChoices[addressId];
                }
            });

            return { assignment, method };
        },

        onSave() {
            if (!this.isValid || this.isSaving) {
                return;
            }
            this.isSaving = true;
            this.syncService.httpClient
                .post(`_action/order-splitting/${this.order.id}/split`, this.buildPayload(), {
                    headers: this.syncService.getBasicHeaders(),
                })
                .then(() => {
                    this.createNotificationSuccess({
                        message: this.$tc('swag-order-splitting-split-modal.savedSuccess'),
                    });
                    this.$emit('split-done');
                })
                .catch((error) => {
                    const detail = error?.response?.data?.errors?.[0]?.detail
                        ?? this.$tc('swag-order-splitting-split-modal.savedError');
                    this.createNotificationError({ message: detail });
                })
                .finally(() => {
                    this.isSaving = false;
                });
        },

        onCancel() {
            this.$emit('modal-close');
        },
    },
});
