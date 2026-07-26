<?php declare(strict_types=1);

namespace Baeumi\OrderSplitting\Api;

use Shopware\Core\Framework\Context;
use Baeumi\OrderSplitting\Core\Checkout\Cart\AdminOrderSplitService;
use Symfony\Component\HttpFoundation\JsonResponse;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\Routing\Attribute\Route;

/**
 * Admin-API endpoints to split / un-split a placed order from the admin UI.
 */
#[Route(defaults: ['_routeScope' => ['api'], '_acl' => ['order.editor']])]
class OrderSplitApiController
{
    public function __construct(
        private readonly AdminOrderSplitService $splitService
    ) {
    }

    #[Route(
        path: '/api/_action/order-splitting/{orderId}/split',
        name: 'api.action.order-splitting.split',
        methods: ['POST']
    )]
    public function split(string $orderId, Request $request, Context $context): Response
    {
        $payload = json_decode($request->getContent() ?: '{}', true);
        $assignments = \is_array($payload['assignment'] ?? null) ? $payload['assignment'] : [];
        $methods = \is_array($payload['method'] ?? null) ? $payload['method'] : [];

        try {
            $this->splitService->split($orderId, $assignments, $methods, $context);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['errors' => [['detail' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }

    #[Route(
        path: '/api/_action/order-splitting/{orderId}/reset',
        name: 'api.action.order-splitting.reset',
        methods: ['POST']
    )]
    public function reset(string $orderId, Context $context): Response
    {
        try {
            $this->splitService->reset($orderId, $context);
        } catch (\RuntimeException $e) {
            return new JsonResponse(['errors' => [['detail' => $e->getMessage()]]], Response::HTTP_BAD_REQUEST);
        }

        return new Response(null, Response::HTTP_NO_CONTENT);
    }
}
