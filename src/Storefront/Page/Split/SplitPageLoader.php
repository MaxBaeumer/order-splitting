<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Storefront\Page\Split;

use Shopware\Core\Checkout\Cart\SalesChannel\CartService;
use Shopware\Core\Checkout\Customer\Aggregate\CustomerAddress\CustomerAddressCollection;
use Shopware\Core\Checkout\Shipping\SalesChannel\AbstractShippingMethodRoute;
use Shopware\Core\Checkout\Shipping\ShippingMethodCollection;
use Shopware\Core\Framework\DataAbstractionLayer\EntityRepository;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Criteria;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Filter\EqualsFilter;
use Shopware\Core\Framework\DataAbstractionLayer\Search\Sorting\FieldSorting;
use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Page\GenericPageLoader;
use Baeumi\OrderSplitting\Core\Checkout\Cart\SplitAssignmentStruct;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\HttpFoundation\Request;

class SplitPageLoader
{
    public function __construct(
        private readonly GenericPageLoader $genericPageLoader,
        private readonly CartService $cartService,
        private readonly EntityRepository $customerAddressRepository,
        private readonly AbstractShippingMethodRoute $shippingMethodRoute,
        private readonly EventDispatcherInterface $eventDispatcher
    ) {
    }

    public function load(Request $request, SalesChannelContext $context): SplitPage
    {
        $customer = $context->getCustomer();
        if ($customer === null) {
            throw new \RuntimeException('Customer must be logged in to load the split page.');
        }

        $page = SplitPage::createFrom($this->genericPageLoader->load($request, $context));

        $cart = $this->cartService->getCart($context->getToken(), $context);
        $page->setCart($cart);

        $criteria = new Criteria();
        $criteria->addFilter(new EqualsFilter('customerId', $customer->getId()));
        $criteria->addAssociation('country');
        $criteria->addAssociation('countryState');
        $criteria->addAssociation('salutation');
        $criteria->addSorting(new FieldSorting('createdAt', FieldSorting::DESCENDING));

        /** @var CustomerAddressCollection $addresses */
        $addresses = $this->customerAddressRepository
            ->search($criteria, $context->getContext())
            ->getEntities();
        $page->setAddresses($addresses);
        $page->setShippingMethods($this->loadAvailableShippingMethods($request, $context));

        $assignment = $cart->getExtensionOfType(
            SplitAssignmentStruct::EXTENSION_KEY,
            SplitAssignmentStruct::class
        ) ?? new SplitAssignmentStruct();
        $page->setAssignment($assignment);

        $this->eventDispatcher->dispatch(new SplitPageLoadedEvent($page, $context, $request));

        return $page;
    }

    /**
     * The shipping methods actually available to this customer (respecting
     * availability rules), i.e. the same set offered on the split page and the
     * confirm page. Used both to render the dropdowns and to validate the
     * submitted per-address method choices.
     */
    public function loadAvailableShippingMethods(Request $request, SalesChannelContext $context): ShippingMethodCollection
    {
        $criteria = new Criteria();
        $criteria->addAssociation('deliveryTime');

        // onlyAvailable restricts to methods the customer may actually use
        // (respecting availability rules), matching the confirm page.
        $request = $request->duplicate();
        $request->query->set('onlyAvailable', '1');

        return $this->shippingMethodRoute->load($request, $context, $criteria)->getShippingMethods();
    }
}
