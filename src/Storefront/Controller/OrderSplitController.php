<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Storefront\Controller;

use Shopware\Core\System\SalesChannel\SalesChannelContext;
use Shopware\Storefront\Controller\StorefrontController;
use Baeumi\OrderSplitting\Core\Checkout\Cart\OrderSplitAvailabilityService;
use Baeumi\OrderSplitting\Core\Checkout\Cart\SplitAssignmentService;
use Baeumi\OrderSplitting\Storefront\Page\Split\SplitPageLoader;
use Symfony\Component\HttpFoundation\RedirectResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Storefront endpoints for the "ship to multiple addresses" step. Placed
 * logically between the cart and the confirm page:
 *   cart -> [split step] -> confirm -> place order
 *
 * All routes require a logged-in customer (_loginRequired) because the split
 * targets are addresses from the customer's address book.
 */
#[Route(defaults: ['_routeScope' => ['storefront']])]
class OrderSplitController extends StorefrontController
{
    public function __construct(
        private readonly SplitPageLoader $splitPageLoader,
        private readonly SplitAssignmentService $assignmentService,
        private readonly OrderSplitAvailabilityService $availabilityService
    ) {
    }

    #[Route(
        path: '/checkout/split',
        name: 'frontend.checkout.split.page',
        options: ['seo' => false],
        defaults: ['_loginRequired' => true, '_noStore' => true],
        methods: ['GET']
    )]
    public function index(Request $request, SalesChannelContext $context): Response
    {
        if ($unavailable = $this->denyIfUnavailable($context)) {
            return $unavailable;
        }

        $page = $this->splitPageLoader->load($request, $context);

        return $this->renderStorefront(
            '@BaeumiOrderSplitting/storefront/page/checkout/split/index.html.twig',
            ['page' => $page]
        );
    }

    #[Route(
        path: '/checkout/split',
        name: 'frontend.checkout.split.save',
        defaults: ['_loginRequired' => true, 'XmlHttpRequest' => false],
        methods: ['POST']
    )]
    public function save(Request $request, SalesChannelContext $context): Response
    {
        if ($unavailable = $this->denyIfUnavailable($context)) {
            return $unavailable;
        }

        // Expected form fields:
        //   assignment[<lineItemId>][<addressId>] = <qty>
        //   method[<addressId>] = <shippingMethodId>
        /** @var array<string, array<string, int|string>> $assignments */
        $assignments = $request->request->all('assignment');
        /** @var array<string, string> $methods */
        $methods = $request->request->all('method');

        try {
            $available = $this->splitPageLoader->loadAvailableShippingMethods($request, $context)->getIds();
            $this->assignmentService->save($assignments, $methods, array_values($available), $context);
        } catch (\RuntimeException $e) {
            $this->addFlash(self::DANGER, $e->getMessage());

            return $this->redirectToRoute('frontend.checkout.split.page');
        }

        $this->addFlash(self::SUCCESS, $this->trans('order-splitting.saved'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }

    /**
     * Saves the current split and re-renders the split page so the storefront JS
     * can swap in the updated cost summary without a full page reload. Invalid
     * intermediate states (e.g. quantities not summing yet) leave the saved cart
     * untouched and simply return the current page.
     */
    #[Route(
        path: '/checkout/split/recalculate',
        name: 'frontend.checkout.split.recalculate',
        defaults: ['_loginRequired' => true, '_noStore' => true, 'XmlHttpRequest' => true],
        methods: ['POST']
    )]
    public function recalculate(Request $request, SalesChannelContext $context): Response
    {
        if ($unavailable = $this->denyIfUnavailable($context)) {
            return $unavailable;
        }

        /** @var array<string, array<string, int|string>> $assignments */
        $assignments = $request->request->all('assignment');
        /** @var array<string, string> $methods */
        $methods = $request->request->all('method');

        try {
            $available = $this->splitPageLoader->loadAvailableShippingMethods($request, $context)->getIds();
            $this->assignmentService->save($assignments, $methods, array_values($available), $context);
        } catch (\RuntimeException) {
            // Keep the last valid split; just re-render with the current cart.
        }

        $page = $this->splitPageLoader->load($request, $context);

        return $this->renderStorefront(
            '@BaeumiOrderSplitting/storefront/page/checkout/split/index.html.twig',
            ['page' => $page]
        );
    }

    #[Route(
        path: '/checkout/split/reset',
        name: 'frontend.checkout.split.reset',
        defaults: ['_loginRequired' => true],
        methods: ['POST']
    )]
    public function reset(Request $request, SalesChannelContext $context): RedirectResponse
    {
        $this->assignmentService->reset($context);

        return $this->redirectToRoute('frontend.checkout.split.page');
    }

    /**
     * Guard: when order splitting is not available for this context (per the
     * configured availability rule), send the customer back to the confirm page
     * with a notice instead of exposing the split step.
     */
    private function denyIfUnavailable(SalesChannelContext $context): ?RedirectResponse
    {
        if ($this->availabilityService->isAvailable($context)) {
            return null;
        }

        $this->addFlash(self::INFO, $this->trans('order-splitting.notAvailable'));

        return $this->redirectToRoute('frontend.checkout.confirm.page');
    }
}
