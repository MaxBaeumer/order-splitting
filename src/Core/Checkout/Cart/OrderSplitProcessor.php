<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Cart\CartProcessorInterface;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryCalculator;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\Delivery\Struct\Delivery;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryDate;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPosition;
use Shopware\Core\Checkout\Cart\Delivery\Struct\DeliveryPositionCollection;
use Shopware\Core\Checkout\Cart\Delivery\Struct\ShippingLocation;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\Price\Struct\CalculatedPrice;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTax;
use Shopware\Core\Checkout\Cart\Tax\Struct\CalculatedTaxCollection;
use Shopware\Core\Checkout\Cart\Tax\Struct\TaxRuleCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodEntity;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Rebuilds the cart delivery collection so that each target address becomes its
 * own Delivery, priced by the selected shipping method.
 *
 * Runs AFTER the core DeliveryProcessor (priority -5200, i.e. last). We read the
 * single default delivery the core built to inherit the selected shipping
 * method and delivery date, then replace the whole collection with one delivery
 * per address. The shipping cost of each delivery is then computed by the core
 * DeliveryCalculator from the shipping method's price matrix and that delivery's
 * own goods (so quantity/price/weight tiers and free-shipping items apply per
 * delivery) - no hardcoded flat rate. The core Processor afterwards sums every
 * delivery's shipping costs into the cart total, and OrderConverter later maps
 * each Delivery to a native order_delivery with its own order_address. We never
 * touch line item prices, so goods totals and tax are untouched.
 */
class OrderSplitProcessor implements CartProcessorInterface
{
    public function __construct(
        private readonly DeliveryCalculator $deliveryCalculator
    ) {
    }

    public function process(
        CartDataCollection $data,
        Cart $original,
        Cart $toCalculate,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $struct = $original->getExtensionOfType(
            SplitAssignmentStruct::EXTENSION_KEY,
            SplitAssignmentStruct::class
        );

        // No split requested (or only one address) -> leave the default delivery.
        if ($struct === null || !$struct->isActive()) {
            return;
        }

        /** @var CustomerAddressCollection|null $addresses */
        $addresses = $data->get(OrderSplitCollector::DATA_KEY);
        if (!$addresses instanceof CustomerAddressCollection) {
            return;
        }

        $default = $toCalculate->getDeliveries()->first();
        if ($default === null) {
            // Nothing shippable in the cart; nothing to split.
            return;
        }

        // Safety guard against interference with other delivery-manipulating
        // extensions (and core multi-delivery, e.g. per-restock-time). We only
        // replace the SINGLE default delivery the core DeliveryProcessor builds.
        // If more than one delivery already exists when we run, another
        // processor built them - do not overwrite their work; leave the cart as
        // is (the split silently does not apply for this cart).
        if ($toCalculate->getDeliveries()->count() > 1) {
            return;
        }

        $defaultMethod = $default->getShippingMethod();
        $deliveryDate = $default->getDeliveryDate();

        // Index the calculated line items so we can look them up by id.
        $lineItems = [];
        foreach ($toCalculate->getLineItems()->getFlat() as $lineItem) {
            $lineItems[$lineItem->getId()] = $lineItem;
        }

        // Invert the assignment to group positions per address id.
        $positionsPerAddress = $this->groupPositionsByAddress($struct, $lineItems, $deliveryDate);

        $deliveries = new DeliveryCollection();
        foreach ($positionsPerAddress as $addressId => $positions) {
            $address = $addresses->get($addressId);
            if ($address === null) {
                continue;
            }

            $deliveries->add(new Delivery(
                new DeliveryPositionCollection($positions),
                $deliveryDate,
                $this->resolveShippingMethod($struct, (string) $addressId, $defaultMethod, $data),
                ShippingLocation::createFromAddress($address),
                // Placeholder; the real cost is computed by DeliveryCalculator
                // below from the shipping method's price matrix.
                new CalculatedPrice(0.0, 0.0, new CalculatedTaxCollection(), new TaxRuleCollection())
            ));
        }

        if ($deliveries->count() === 0) {
            return;
        }

        $toCalculate->setDeliveries($deliveries);

        // In an order recalculation the core may have stored the order's original
        // (single) shipping cost as a "manual" cost; that would then be applied
        // to every split delivery. Drop it so each delivery is priced by its own
        // method below. Harmless in the storefront cart (extension not present).
        $toCalculate->removeExtension(DeliveryProcessor::MANUAL_SHIPPING_COSTS);

        // Price each delivery via the shipping method's configured rules, based
        // on the goods routed to it. The shipping method (with its prices) is
        // already in $data from the core DeliveryProcessor that ran before us.
        $this->deliveryCalculator->calculate($data, $toCalculate, $deliveries, $context);
    }

    /**
     * The shipping method chosen for this address (from the split state), or the
     * cart's default method when none was chosen / it is unavailable. The chosen
     * method entity (with prices) is provided by OrderSplitCollector under the
     * DeliveryProcessor key, which is also where DeliveryCalculator looks it up.
     */
    private function resolveShippingMethod(
        SplitAssignmentStruct $struct,
        string $addressId,
        ShippingMethodEntity $default,
        CartDataCollection $data
    ): ShippingMethodEntity {
        $methodId = $struct->getMethodForAddress($addressId);
        if ($methodId === null || $methodId === $default->getId()) {
            return $default;
        }

        $method = $data->get(DeliveryProcessor::buildKey($methodId));

        return $method instanceof ShippingMethodEntity ? $method : $default;
    }

    /**
     * @param array<string, LineItem> $lineItems
     *
     * @return array<string, DeliveryPosition[]>
     */
    private function groupPositionsByAddress(
        SplitAssignmentStruct $struct,
        array $lineItems,
        DeliveryDate $deliveryDate
    ): array {
        $result = [];

        foreach ($struct->getAssignments() as $lineItemId => $perAddress) {
            $lineItem = $lineItems[$lineItemId] ?? null;
            if ($lineItem === null || $lineItem->getPrice() === null) {
                continue;
            }

            foreach ($perAddress as $addressId => $quantity) {
                $quantity = (int) $quantity;
                if ($quantity <= 0) {
                    continue;
                }

                // The identifier MUST equal the cart line item id. When the
                // order is placed, DeliveryTransformer maps each delivery
                // position to a converted order line item by looking it up in a
                // map keyed by line item id (see LineItemTransformer). Any other
                // identifier (e.g. "<lineItemId>-<addressId>") is not found and
                // the position is silently dropped, so the order would end up
                // with deliveries that have NO positions. The same line item may
                // legitimately reappear in another delivery (a product split
                // across addresses); those live in separate DeliveryPosition
                // collections, so reusing the id here is safe.
                $result[$addressId][] = new DeliveryPosition(
                    $lineItemId,
                    clone $lineItem,
                    $quantity,
                    $this->partialPrice($lineItem->getPrice(), $quantity),
                    $deliveryDate
                );
            }
        }

        return $result;
    }

    /**
     * Scale a line item's calculated price down to a partial quantity so the
     * delivery position reflects only the goods routed to this address.
     */
    private function partialPrice(CalculatedPrice $full, int $quantity): CalculatedPrice
    {
        $unitPrice = $full->getUnitPrice();
        $total = $unitPrice * $quantity;
        $originalQty = max(1, $full->getQuantity());
        $factor = $quantity / $originalQty;

        $taxes = new CalculatedTaxCollection();
        foreach ($full->getCalculatedTaxes() as $tax) {
            $taxes->add(new CalculatedTax(
                $tax->getTax() * $factor,
                $tax->getTaxRate(),
                $tax->getPrice() * $factor
            ));
        }

        return new CalculatedPrice(
            $unitPrice,
            $total,
            $taxes,
            $full->getTaxRules(),
            $quantity
        );
    }
}
