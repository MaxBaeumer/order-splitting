<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartRuleLoader;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Order\OrderConversionContext;
use Shopware\Core\Checkout\Cart\Order\OrderConverter;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Processor;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Order\OrderEntity;
use Shopware\Core\Framework\Context;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Baeumi\OrderSplitting\Installer\DefaultFlowInstaller;

/**
 * Splits (or un-splits) an already placed order from the admin.
 *
 * We reuse the storefront split logic end to end by round-tripping the order
 * through a cart: order -> convertToCart -> attach SplitAssignmentStruct ->
 * recalculate (OrderSplitCollector + OrderSplitProcessor rebuild the deliveries)
 * -> convertToOrder -> persist. This mirrors Shopware's own RecalculationService
 * flow, but with two differences we have to handle ourselves:
 *   1. it runs in a dedicated order version that is merged at the end, and
 *   2. it fully REPLACES the deliveries. Core's recalc only deletes discount
 *      (negative) deliveries, so we explicitly delete the previous deliveries
 *      that the split replaced - otherwise the old delivery would be orphaned.
 */
class AdminOrderSplitService
{
    public function __construct(
        private readonly EntityRepository $orderRepository,
        private readonly EntityRepository $orderDeliveryRepository,
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $orderTagRepository,
        private readonly EntityRepository $orderTransactionRepository,
        private readonly OrderConverter $orderConverter,
        private readonly Processor $processor,
        private readonly CartRuleLoader $cartRuleLoader
    ) {
    }

    /**
     * @param array<string, array<string, int|string>> $rawAssignments keyed orderLineItemId => [customerAddressId => qty]
     * @param array<string, string>                    $rawMethods     keyed customerAddressId => shippingMethodId
     *
     * @throws \RuntimeException on invalid input
     */
    public function split(string $orderId, array $rawAssignments, array $rawMethods, Context $context): void
    {
        $this->apply($orderId, $rawAssignments, $rawMethods, $context);
    }

    /**
     * Revert the order to a single delivery (recalculate without a split).
     */
    public function reset(string $orderId, Context $context): void
    {
        $this->apply($orderId, [], [], $context);
    }

    /**
     * @param array<string, array<string, int|string>> $rawAssignments
     * @param array<string, string>                    $rawMethods
     */
    private function apply(string $orderId, array $rawAssignments, array $rawMethods, Context $context): void
    {
        $versionId = $this->orderRepository->createVersion($orderId, $context);
        $versionContext = $context->createWithVersionId($versionId);

        try {
            $order = $this->fetchOrder($orderId, $versionContext);

            $salesChannelContext = $this->orderConverter->assembleSalesChannelContext($order, $versionContext);
            $cart = $this->orderConverter->convertToCart($order, $versionContext);

            $struct = $this->buildStruct($rawAssignments, $rawMethods, $order, $cart, $versionContext);
            if ($struct !== null) {
                $cart->addExtension(SplitAssignmentStruct::EXTENSION_KEY, $struct);
            }

            $recalculatedCart = $this->recalculateCart($cart, $salesChannelContext);

            $conversionContext = (new OrderConversionContext())
                ->setIncludeCustomer(false)
                ->setIncludeBillingAddress(false)
                ->setIncludeTransactions(false)
                ->setIncludePersistentData(false)
                ->setIncludeDeliveries(true);

            $orderData = $this->orderConverter->convertToOrder($recalculatedCart, $salesChannelContext, $conversionContext);
            $orderData['id'] = $orderId;
            // Preserve the order's current state; the new deliveries keep their
            // default (open) delivery state. State-machine fields are protected,
            // so all writes below run in SYSTEM scope.
            $orderData['stateId'] = $order->getStateId();

            $newDeliveryIds = array_column($orderData['deliveries'] ?? [], 'id');
            $oldDeliveryIds = $order->getDeliveries() !== null ? $order->getDeliveries()->getIds() : [];

            // Upsert first (adds the new deliveries + repoints primaryOrderDeliveryId),
            // then delete the deliveries the split replaced.
            $versionContext->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $ctx) => $this->orderRepository->upsert([$orderData], $ctx)
            );

            $toDelete = array_values(array_diff(array_values($oldDeliveryIds), $newDeliveryIds));
            if ($toDelete !== []) {
                $versionContext->scope(
                    Context::SYSTEM_SCOPE,
                    fn (Context $ctx) => $this->orderDeliveryRepository->delete(
                        array_map(static fn (string $id) => ['id' => $id], $toDelete),
                        $ctx
                    )
                );
            }

            // Merge replays the version's writes to the live order; the delivery
            // state fields require SYSTEM scope here too.
            $context->scope(
                Context::SYSTEM_SCOPE,
                fn (Context $ctx) => $this->orderRepository->merge($versionId, $ctx)
            );

            // Splitting changes the shipping total, so the order total changes.
            // Keep the payment transaction amount in sync with the new total, so
            // the order stays internally consistent (reports / payment plugins
            // read transaction.amount). This only updates the amount field - it
            // does NOT capture/refund; reconciling an already-paid difference is
            // the merchant's responsibility.
            $this->syncTransactionAmount($order, $recalculatedCart, $context);

            // Keep the "Order splitted" tag in sync. The flow only tags orders
            // placed in the storefront, so admin splits (and resets) must
            // (un)assign it here.
            $this->syncSplitTag($orderId, $struct !== null, $context);
        } catch (\Throwable $e) {
            // Discard the working version so a failed split leaves no draft behind.
            try {
                $this->orderRepository->deleteVersion($versionId, $context);
            } catch (\Throwable) {
                // ignore cleanup failures
            }
            throw $e;
        }
    }

    /**
     * Update the active transaction's amount to match the recalculated order
     * total. Data-only (no payment capture/refund). Skips cancelled/failed
     * transactions and leaves the transaction state untouched.
     */
    private function syncTransactionAmount(OrderEntity $order, \Shopware\Core\Checkout\Cart\Cart $recalculatedCart, Context $context): void
    {
        $transaction = $order->getPrimaryOrderTransaction();
        if ($transaction === null) {
            foreach ($order->getTransactions() ?? [] as $candidate) {
                $state = $candidate->getStateMachineState()?->getTechnicalName();
                if (!\in_array($state, ['cancelled', 'failed'], true)) {
                    $transaction = $candidate;
                }
            }
        }

        if ($transaction === null) {
            return;
        }

        $cartPrice = $recalculatedCart->getPrice();
        $total = $cartPrice->getTotalPrice();
        $amount = new CalculatedPrice(
            $total,
            $total,
            $cartPrice->getCalculatedTaxes(),
            $cartPrice->getTaxRules()
        );

        $context->scope(
            Context::SYSTEM_SCOPE,
            fn (Context $ctx) => $this->orderTransactionRepository->update([[
                'id' => $transaction->getId(),
                'amount' => $amount,
            ]], $ctx)
        );
    }

    /**
     * Assign the "Order splitted" tag when the order is now split, or remove it
     * when it was reset to a single delivery. Uses the same deterministic tag id
     * as the flow, creating the tag on first use (idempotent).
     */
    private function syncSplitTag(string $orderId, bool $isSplit, Context $context): void
    {
        $tagId = DefaultFlowInstaller::tagId();

        if ($isSplit) {
            $this->orderRepository->update([[
                'id' => $orderId,
                'tags' => [['id' => $tagId, 'name' => DefaultFlowInstaller::TAG_NAME]],
            ]], $context);

            return;
        }

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('orderId', $orderId));
        $criteria->addFilter(new EqualsFilter('tagId', $tagId));

        $ids = $this->orderTagRepository->searchIds($criteria, $context)->getIds();
        if ($ids !== []) {
            $this->orderTagRepository->delete(array_values($ids), $context);
        }
    }

    private function fetchOrder(string $orderId, Context $context): OrderEntity
    {
        $criteria = (new Criteria([$orderId]))->addAssociations([
            'orderCustomer',
            'primaryOrderTransaction',
            'primaryOrderDelivery',
            'lineItems.downloads',
            'transactions.stateMachineState',
            'deliveries.shippingMethod.tax',
            'deliveries.shippingMethod.deliveryTime',
            'deliveries.positions.orderLineItem',
            'deliveries.shippingOrderAddress.country',
            'deliveries.shippingOrderAddress.countryState',
        ]);

        $order = $this->orderRepository->search($criteria, $context)->getEntities()->first();
        if (!$order instanceof OrderEntity) {
            throw new \RuntimeException(sprintf('Order "%s" not found.', $orderId));
        }

        return $order;
    }

    private function recalculateCart(\Shopware\Core\Checkout\Cart\Cart $cart, SalesChannelContext $context): \Shopware\Core\Checkout\Cart\Cart
    {
        return $context->live(function (SalesChannelContext $live) use ($cart): \Shopware\Core\Checkout\Cart\Cart {
            $permissions = $live->getPermissions();
            // Force the core DeliveryProcessor to REBUILD deliveries instead of
            // preserving the order's existing ones (and reusing the order's flat
            // shipping cost). For a split, OrderSplitProcessor then replaces them
            // per address and prices each by its method; for a reset, the fresh
            // single delivery stands, collapsing the order back to one delivery.
            unset($permissions[DeliveryProcessor::SKIP_DELIVERY_PRICE_RECALCULATION]);

            $behavior = new CartBehavior($permissions, true, true);
            $processed = $this->processor->process($cart, $live, $behavior);

            return $this->cartRuleLoader->loadByCart($live, $processed, $behavior)->getCart();
        });
    }

    /**
     * Translate the admin input (keyed by order line item id + customer address
     * id) into a SplitAssignmentStruct keyed by the cart line item identifier
     * (which convertToCart derives from order_line_item.identifier). Returns null
     * when no active split is requested (reset / single delivery).
     *
     * @param array<string, array<string, int|string>> $rawAssignments
     * @param array<string, string>                    $rawMethods
     */
    private function buildStruct(array $rawAssignments, array $rawMethods, OrderEntity $order, \Shopware\Core\Checkout\Cart\Cart $cart, Context $context): ?SplitAssignmentStruct
    {
        if ($rawAssignments === []) {
            return null;
        }

        $lineItems = $order->getLineItems();
        if ($lineItems === null) {
            return null;
        }

        // order line item id -> [identifier, quantity]
        $meta = [];
        foreach ($lineItems as $lineItem) {
            $meta[$lineItem->getId()] = [$lineItem->getIdentifier(), $lineItem->getQuantity()];
        }

        $assignments = [];
        foreach ($rawAssignments as $orderLineItemId => $perAddress) {
            if (!isset($meta[$orderLineItemId])) {
                continue;
            }
            [$identifier, $expected] = $meta[$orderLineItemId];

            $sum = 0;
            $normalized = [];
            foreach ($perAddress as $addressId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $normalized[(string) $addressId] = $qty;
                $sum += $qty;
            }

            if ($normalized === []) {
                continue;
            }
            if ($sum !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Split quantities for item "%s" sum to %d but must equal %d.',
                    $lineItems->get($orderLineItemId)?->getLabel() ?? $orderLineItemId,
                    $sum,
                    $expected
                ));
            }

            $assignments[$identifier] = $normalized;
        }

        if ($assignments === []) {
            return null;
        }

        // Every shippable line item must be assigned, otherwise it would be
        // dropped from all deliveries (the split only ships assigned items).
        foreach ($cart->getLineItems()->getFlat() as $cartLineItem) {
            if ($cartLineItem->getDeliveryInformation() === null) {
                continue;
            }
            if (!isset($assignments[$cartLineItem->getId()])) {
                throw new \RuntimeException(sprintf(
                    'Product "%s" must be assigned to at least one address.',
                    $cartLineItem->getLabel() ?? $cartLineItem->getId()
                ));
            }
        }

        $this->assertAddressesBelongToCustomer($assignments, $order, $context);

        $methods = [];
        foreach ($rawMethods as $addressId => $methodId) {
            $methodId = (string) $methodId;
            if ($methodId !== '') {
                $methods[(string) $addressId] = $methodId;
            }
        }

        $struct = new SplitAssignmentStruct($assignments, $methods);

        return $struct->isActive() ? $struct : null;
    }

    /**
     * @param array<string, array<string, int>> $assignments
     */
    private function assertAddressesBelongToCustomer(array $assignments, OrderEntity $order, Context $context): void
    {
        $customerId = $order->getOrderCustomer()?->getCustomerId();
        if ($customerId === null) {
            throw new \RuntimeException('This order has no registered customer, so it cannot be split from the address book.');
        }

        $addressIds = [];
        foreach ($assignments as $perAddress) {
            foreach (array_keys($perAddress) as $addressId) {
                $addressIds[$addressId] = true;
            }
        }
        $addressIds = array_keys($addressIds);

        /** @var CustomerAddressCollection $addresses */
        $addresses = $this->customerAddressRepository
            ->search(new Criteria($addressIds), $context)
            ->getEntities();

        foreach ($addressIds as $addressId) {
            $address = $addresses->get($addressId);
            if ($address === null || $address->getCustomerId() !== $customerId) {
                throw new \RuntimeException(sprintf('Address "%s" does not belong to this order\'s customer.', $addressId));
            }
        }
    }
}
