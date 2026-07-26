import './module/sw-order/page/sw-order-detail';
import './module/sw-order/view/sw-order-detail-details';
import './module/sw-order/component/sw-order-general-info';
import './module/sw-order/component/sw-order-details-state-card';
import './module/swag-order-splitting-settings';
import './rule-condition';

// Snippets used by the per-delivery cards (Details tab) and the read-only
// delivery-status overview (General tab).
Shopware.Locale.extend('en-GB', {
    'swag-order-splitting': {
        card: {
            delivery: 'Delivery',
            shipping: 'Shipping',
            itemsHeadline: 'Products sent to this address:',
        },
        general: {
            manageOnDetails: 'Change delivery statuses on the Details tab',
        },
        rule: {
            group: 'Order Splitting (Extension)',
        },
    },
    'swag-order-splitting-settings': {
        title: 'Order Splitting',
        description: 'Configure order splitting availability.',
        saved: 'Order Splitting settings saved.',
    },
    'swag-order-splitting-split-modal': {
        title: 'Split order across delivery addresses',
        intro: "Assign each product's quantity to the customer's saved addresses. Every address becomes its own delivery, priced by its shipping method.",
        totalQuantity: 'Total quantity',
        chooseAddress: 'Choose an address',
        chooseMethod: 'Choose a method',
        addAddress: 'Add another address',
        remove: 'Remove',
        methodsHeadline: 'Shipping method per delivery address',
        apply: 'Split order',
        openButton: 'Split order',
        resetButton: 'Reset to single delivery',
        savedSuccess: 'The order was split into multiple deliveries.',
        savedError: 'The order could not be split.',
        errorSum: 'Quantities must sum to {total} (currently {sum}).',
        errorMissingAddress: 'Choose an address for every row.',
        errorDuplicate: 'Each address may be used only once per product.',
    },
    // Rule Builder condition label (auto-rendered PHP rule).
    global: {
        'sw-condition': {
            condition: {
                orderSplittingDeliveryAddressCount: 'Number of delivery addresses',
            },
        },
    },
});

Shopware.Locale.extend('de-DE', {
    'swag-order-splitting': {
        card: {
            delivery: 'Lieferung',
            shipping: 'Versand',
            itemsHeadline: 'An diese Adresse gesendete Artikel:',
        },
        general: {
            manageOnDetails: 'Lieferstatus im Tab "Details" aendern',
        },
        rule: {
            group: 'Bestellung aufteilen (Extension)',
        },
    },
    'swag-order-splitting-settings': {
        title: 'Bestellung aufteilen',
        description: 'Verfuegbarkeit der Bestellaufteilung konfigurieren.',
        saved: 'Einstellungen gespeichert.',
    },
    'swag-order-splitting-split-modal': {
        title: 'Bestellung auf Lieferadressen aufteilen',
        intro: 'Verteilen Sie die Menge jedes Produkts auf die gespeicherten Adressen des Kunden. Jede Adresse wird zu einer eigenen Lieferung, die nach ihrer Versandart berechnet wird.',
        totalQuantity: 'Gesamtmenge',
        chooseAddress: 'Adresse waehlen',
        chooseMethod: 'Versandart waehlen',
        addAddress: 'Weitere Adresse hinzufuegen',
        remove: 'Entfernen',
        methodsHeadline: 'Versandart je Lieferadresse',
        apply: 'Bestellung aufteilen',
        openButton: 'Bestellung aufteilen',
        resetButton: 'Auf eine Lieferung zuruecksetzen',
        savedSuccess: 'Die Bestellung wurde in mehrere Lieferungen aufgeteilt.',
        savedError: 'Die Bestellung konnte nicht aufgeteilt werden.',
        errorSum: 'Die Mengen muessen {total} ergeben (aktuell {sum}).',
        errorMissingAddress: 'Bitte fuer jede Zeile eine Adresse waehlen.',
        errorDuplicate: 'Jede Adresse darf je Produkt nur einmal verwendet werden.',
    },
    global: {
        'sw-condition': {
            condition: {
                orderSplittingDeliveryAddressCount: 'Anzahl der Lieferadressen',
            },
        },
    },
});
