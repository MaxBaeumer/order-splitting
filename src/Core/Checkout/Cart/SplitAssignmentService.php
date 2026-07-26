<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Core\Checkout\Cart;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Cart\LineItem\LineItem;
use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\System\SalesChannel\SalesChannelContext;

/**
 * Owns reading, validating and writing the split assignment. The controller
 * talks only to this service; the processor only reads the struct.
 */
class SplitAssignmentService
{
    public function __construct(
        private readonly CartService $cartService,
        private readonly EntityRepository $customerAddressRepository
    ) {
    }

    public function getStruct(Cart $cart): SplitAssignmentStruct
    {
        $struct = $cart->getExtensionOfType(
            SplitAssignmentStruct::EXTENSION_KEY,
            SplitAssignmentStruct::class
        );

        return $struct ?? new SplitAssignmentStruct();
    }

    /**
     * Validate the raw storefront input, attach it to the cart and recalculate.
     *
     * @param array<string, array<string, int|string>> $rawAssignments
     *        keyed lineItemId => [addressId => qty]
     * @param array<string, string> $rawMethods
     *        keyed addressId => shippingMethodId (optional; empty falls back to
     *        the cart's default shipping method per delivery)
     * @param string[] $availableMethodIds
     *        ids of the shipping methods actually available to the customer;
     *        chosen methods outside this set are dropped (fall back to default)
     *
     * @throws \RuntimeException on invalid quantities / unknown line items
     */
    public function save(array $rawAssignments, array $rawMethods, array $availableMethodIds, SalesChannelContext $context): Cart
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);

        $normalized = $this->validateAndNormalize($rawAssignments, $cart);
        $this->assertAddressesBelongToCustomer($normalized, $context);

        $methods = $this->validateMethods($rawMethods, $normalized, $availableMethodIds);

        $struct = new SplitAssignmentStruct($normalized, $methods);
        $cart->addExtension(SplitAssignmentStruct::EXTENSION_KEY, $struct);

        return $this->recalculate($cart, $context);
    }

    public function reset(SalesChannelContext $context): Cart
    {
        $cart = $this->cartService->getCart($context->getToken(), $context);
        $cart->removeExtension(SplitAssignmentStruct::EXTENSION_KEY);

        return $this->recalculate($cart, $context);
    }

    /**
     * Re-run the processor pipeline (so OrderSplitProcessor rebuilds the
     * deliveries) AND refresh the in-request cart cache. CartService::recalculate
     * persists the new cart but does NOT update its own in-memory cache, so a
     * subsequent getCart() in the same request (e.g. the split page loader
     * behind the AJAX recalculation) would otherwise return the stale cart.
     */
    private function recalculate(Cart $cart, SalesChannelContext $context): Cart
    {
        $cart = $this->cartService->recalculate($cart, $context);
        $this->cartService->setCart($cart);

        return $cart;
    }

    /**
     * @param array<string, array<string, int|string>> $raw
     *
     * @return array<string, array<string, int>>
     */
    private function validateAndNormalize(array $raw, Cart $cart): array
    {
        $normalized = [];

        foreach ($this->deliverableLineItems($cart) as $lineItem) {
            $id = $lineItem->getId();
            $expected = $lineItem->getQuantity();

            // No entry for this item => everything stays on the default address.
            if (!isset($raw[$id])) {
                continue;
            }

            $sum = 0;
            foreach ($raw[$id] as $addressId => $qty) {
                $qty = (int) $qty;
                if ($qty <= 0) {
                    continue;
                }
                $normalized[$id][(string) $addressId] = $qty;
                $sum += $qty;
            }

            if ($sum !== $expected) {
                throw new \RuntimeException(sprintf(
                    'Split quantities for line item "%s" sum to %d but must equal %d.',
                    $lineItem->getLabel() ?? $id,
                    $sum,
                    $expected
                ));
            }
        }

        return $normalized;
    }

    /**
     * @param array<string, array<string, int>> $normalized
     */
    private function assertAddressesBelongToCustomer(array $normalized, SalesChannelContext $context): void
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            throw new \RuntimeException('A logged-in customer is required to split an order.');
        }

        $addressIds = [];
        foreach ($normalized as $perAddress) {
            foreach (array_keys($perAddress) as $addressId) {
                $addressIds[$addressId] = true;
            }
        }
        $addressIds = array_keys($addressIds);
        if ($addressIds === []) {
            return;
        }

        $criteria = new Criteria($addressIds);
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');

        /** @var CustomerAddressCollection $addresses */
        $addresses = $this->customerAddressRepository
            ->search($criteria, $context->getContext())
            ->getEntities();

        foreach ($addressIds as $addressId) {
            $address = $addresses->get($addressId);
            if ($address === null || $address->getCustomerId() !== $customer->getId()) {
                throw new \RuntimeException(sprintf('Address "%s" does not belong to the customer.', $addressId));
            }
        }
    }

    /**
     * Keep only method choices for addresses that are part of the split, and
     * only when the referenced method is one the customer may actually use
     * (`$availableMethodIds`, computed the same way as the confirm page). Any
     * other id — inactive, from another sales channel, or blocked by an
     * availability rule — is dropped, so that delivery falls back to the cart's
     * default method. This prevents a crafted request from applying an arbitrary
     * (e.g. cheaper) shipping method's price.
     *
     * @param array<string, string>             $rawMethods         addressId => shippingMethodId
     * @param array<string, array<string, int>> $normalized         lineItemId => [addressId => qty]
     * @param string[]                          $availableMethodIds
     *
     * @return array<string, string>
     */
    private function validateMethods(array $rawMethods, array $normalized, array $availableMethodIds): array
    {
        if ($rawMethods === []) {
            return [];
        }

        $splitAddressIds = [];
        foreach ($normalized as $perAddress) {
            foreach (array_keys($perAddress) as $addressId) {
                $splitAddressIds[$addressId] = true;
            }
        }

        $available = array_flip(array_map('strval', $availableMethodIds));

        $result = [];
        foreach ($rawMethods as $addressId => $methodId) {
            $addressId = (string) $addressId;
            $methodId = (string) $methodId;
            if ($methodId === '' || !isset($splitAddressIds[$addressId]) || !isset($available[$methodId])) {
                continue;
            }
            $result[$addressId] = $methodId;
        }

        return $result;
    }

    /**
     * @return LineItem[] flattened, deliverable (shippable) line items only
     */
    private function deliverableLineItems(Cart $cart): array
    {
        $result = [];
        foreach ($cart->getLineItems()->getFlat() as $lineItem) {
            $info = $lineItem->getDeliveryInformation();
            if ($info === null) {
                continue; // e.g. vouchers / discounts are not shippable
            }
            $result[] = $lineItem;
        }

        return $result;
    }
}
