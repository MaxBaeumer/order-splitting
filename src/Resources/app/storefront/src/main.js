import OrderSplitPlugin from './order-split/order-split.plugin';

const registry = window.PluginManager;

// Register on any element carrying [data-order-split]; the split page wrapper
// has this attribute.
registry.register('OrderSplit', OrderSplitPlugin, '[data-order-split]');
