<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Storefront\Page\Split;

use Shopware\Core\Checkout\Cart\Cart;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Storefront\Page\Page;
use Baeumi\OrderSplitting\Core\Checkout\Cart\SplitAssignmentStruct;

/**
 * Page object for the split step. Extends the generic storefront Page so it
 * inherits header, footer, meta information and can be rendered by the normal
 * storefront layout.
 */
class SplitPage extends Page
{
    protected Cart $cart;

    protected CustomerAddressCollection $addresses;

    protected SplitAssignmentStruct $assignment;

    protected ShippingMethodCollection $shippingMethods;

    public function getCart(): Cart
    {
        return $this->cart;
    }

    public function setCart(Cart $cart): void
    {
        $this->cart = $cart;
    }

    public function getAddresses(): CustomerAddressCollection
    {
        return $this->addresses;
    }

    public function setAddresses(CustomerAddressCollection $addresses): void
    {
        $this->addresses = $addresses;
    }

    public function getAssignment(): SplitAssignmentStruct
    {
        return $this->assignment;
    }

    public function setAssignment(SplitAssignmentStruct $assignment): void
    {
        $this->assignment = $assignment;
    }

    public function getShippingMethods(): ShippingMethodCollection
    {
        return $this->shippingMethods;
    }

    public function setShippingMethods(ShippingMethodCollection $shippingMethods): void
    {
        $this->shippingMethods = $shippingMethods;
    }
}
