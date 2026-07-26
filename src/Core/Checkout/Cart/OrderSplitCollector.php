<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\CartBehavior;
use Shopware\Core\Checkout\Cart\CartDataCollectorInterface;
use Shopware\Core\Checkout\Cart\Delivery\DeliveryProcessor;
use Shopware\Core\Checkout\Cart\LineItem\CartDataCollection;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Loads the data the split processor needs into the CartDataCollection:
 *  - the referenced CustomerAddress entities (address of each delivery), and
 *  - the shipping methods chosen per delivery, stored under the same key the
 *    core DeliveryProcessor uses (`shipping-method-<id>`) so the core
 *    DeliveryCalculator can price a delivery with a method other than the cart's
 *    default (e.g. Express for one address, Standard for another).
 *
 * Collectors are the correct place for database access in the cart pipeline;
 * the processor stays side-effect free and only reads what the collector
 * prepared, keeping these lookups out of every price recalculation loop.
 */
class OrderSplitCollector implements CartDataCollectorInterface
{
    public const DATA_KEY = 'order-split-addresses';

    public function __construct(
        private readonly EntityRepository $customerAddressRepository,
        private readonly EntityRepository $shippingMethodRepository
    ) {
    }

    public function collect(
        CartDataCollection $data,
        Cart $original,
        SalesChannelContext $context,
        CartBehavior $behavior
    ): void {
        $struct = $original->getExtensionOfType(
            SplitAssignmentStruct::EXTENSION_KEY,
            SplitAssignmentStruct::class
        );

        if ($struct === null || !$struct->isActive()) {
            return;
        }

        $criteria = new Criteria($struct->getAddressIds());
        $criteria->setTitle('order-splitting::addresses');
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addAssociation('salutation');

        /** @var CustomerAddressCollection $addresses */
        $addresses = $this->customerAddressRepository
            ->search($criteria, $context->getContext())
            ->getEntities();

        $data->set(self::DATA_KEY, $addresses);

        $this->loadShippingMethods($struct, $data, $context);
    }

    private function loadShippingMethods(
        SplitAssignmentStruct $struct,
        CartDataCollection $data,
        SalesChannelContext $context
    ): void {
        // Only methods not already provided by the core DeliveryProcessor.
        $missing = array_filter(
            $struct->getMethodIds(),
            static fn (string $id): bool => !$data->has(DeliveryProcessor::buildKey($id))
        );

        if ($missing === []) {
            return;
        }

        $criteria = new Criteria(array_values($missing));
        $criteria->setTitle('order-splitting::shipping-methods');
        $criteria->addAssociation('prices');
        $criteria->addAssociation('deliveryTime');
        $criteria->addAssociation('tax');

        /** @var ShippingMethodCollection $methods */
        $methods = $this->shippingMethodRepository
            ->search($criteria, $context->getContext())
            ->getEntities();

        foreach ($methods as $method) {
            $data->set(DeliveryProcessor::buildKey($method->getId()), $method);
        }
    }
}
