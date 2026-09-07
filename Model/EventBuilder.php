<?php
/**
 * Builds the event payloads for one page view.
 *
 * This runs behind an uncached request, never inside the rendered page, so the
 * full page cache can never hand one visitor's hashed data to another.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model;

use Degriz\OpenAiAds\Model\Api\Client;
use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product\Visibility;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Quote\Model\Quote;
use Magento\Sales\Api\Data\OrderInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class EventBuilder
{
    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly EventQueue $queue,
        private readonly Money $money,
        private readonly Client $client,
        private readonly CheckoutSession $checkoutSession,
        private readonly ProductRepositoryInterface $productRepository,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param  array<string, mixed> $context page_type, product_id, query, title
     * @return array<int, array<string, mixed>>
     */
    public function build(array $context): array
    {
        $events = [];

        foreach ($this->queue->take() as $queued) {
            if ($this->config->isEventEnabled((string) $queued['event'])) {
                $events[] = $queued;
            }
        }

        if ($this->config->isEventEnabled('page_viewed')) {
            $events[] = $this->pageViewed($context);
        }

        $event = match ($context['page_type'] ?? '') {
            'product' => $this->contentsViewed($context),
            'checkout' => $this->checkoutStarted(),
            'success' => $this->orderCreated(),
            'search' => $this->search($context),
            default => null,
        };

        if ($event !== null) {
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>
     */
    private function pageViewed(array $context): array
    {
        $pageType = (string) ($context['page_type'] ?? '');

        return [
            'event' => 'page_viewed',
            'data' => [
                'type' => 'contents',
                'contents' => [[
                    'id' => $pageType !== '' ? $pageType : 'page',
                    'name' => (string) ($context['title'] ?? ''),
                    'content_type' => 'page',
                ]],
            ],
            'event_id' => $this->queue->generateEventId('page_viewed'),
            'options' => [],
        ];
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function contentsViewed(array $context): ?array
    {
        if (!$this->config->isEventEnabled('contents_viewed') || empty($context['product_id'])) {
            return null;
        }

        try {
            $product = $this->productRepository->getById(
                (int) $context['product_id'],
                false,
                (int) $this->storeManager->getStore()->getId()
            );
        } catch (\Exception $e) {
            return null;
        }

        $visible = [
            Visibility::VISIBILITY_IN_CATALOG,
            Visibility::VISIBILITY_IN_SEARCH,
            Visibility::VISIBILITY_BOTH,
        ];

        if (!in_array((int) $product->getVisibility(), $visible, true)) {
            return null;
        }

        $currency = $this->getCurrency();
        $price = (float) ($product->getFinalPrice() ?: $product->getPrice());

        return [
            'event' => 'contents_viewed',
            'data' => [
                'type' => 'contents',
                'amount' => $this->money->toMinorUnits($price, $currency),
                'currency' => $currency,
                'contents' => [[
                    'id' => (string) $product->getSku(),
                    'name' => (string) $product->getName(),
                    'content_type' => 'product',
                    'amount' => $this->money->toMinorUnits($price, $currency),
                    'currency' => $currency,
                ]],
            ],
            'event_id' => $this->queue->generateEventId('contents_viewed'),
            'options' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function checkoutStarted(): ?array
    {
        if (!$this->config->isEventEnabled('checkout_started')) {
            return null;
        }

        try {
            $quote = $this->checkoutSession->getQuote();
        } catch (\Exception $e) {
            return null;
        }

        if (!$quote->getId() || !$quote->getItemsCount()) {
            return null;
        }

        // One checkout_started per quote, however many times the page reloads.
        if ($this->queue->getFlag('checkout_started') == $quote->getId()) {
            return null;
        }
        $this->queue->setFlag('checkout_started', $quote->getId());

        $eventId = $this->queue->generateEventId('checkout_started');
        $data = $this->getQuoteData($quote);

        $this->mirror('checkout_started', $data, $eventId);

        return [
            'event' => 'checkout_started',
            'data' => $data,
            'event_id' => $eventId,
            'options' => [],
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function orderCreated(): ?array
    {
        if (!$this->config->isEventEnabled('order_created')) {
            return null;
        }

        $order = $this->identity->getLastPlacedOrder();
        if ($order === null) {
            return null;
        }

        return [
            'event' => 'order_created',
            'data' => $this->getOrderData($order),
            // Same ID the observer used server-side, so the two are deduplicated.
            'event_id' => 'order_created-' . $order->getIncrementId(),
            'options' => [],
        ];
    }

    /**
     * @param  array<string, mixed> $context
     * @return array<string, mixed>|null
     */
    private function search(array $context): ?array
    {
        if (!$this->config->isEventEnabled('search') || empty($context['query'])) {
            return null;
        }

        return [
            'event' => 'custom',
            'data' => [
                'type' => 'custom',
                'contents' => [[
                    'id' => 'search',
                    'name' => (string) $context['query'],
                    'content_type' => 'search',
                ]],
            ],
            'event_id' => $this->queue->generateEventId('search'),
            'options' => ['custom_event_name' => 'search'],
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getQuoteData(Quote $quote): array
    {
        $currency = (string) ($quote->getQuoteCurrencyCode() ?: $this->getCurrency());
        $contents = [];

        foreach ($quote->getAllVisibleItems() as $item) {
            $contents[] = [
                'id' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'content_type' => 'product',
                'quantity' => (int) $item->getQty(),
                'amount' => $this->money->toMinorUnits($item->getPriceInclTax(), $currency),
                'currency' => $currency,
            ];
        }

        return [
            'type' => 'contents',
            'amount' => $this->money->toMinorUnits($quote->getGrandTotal(), $currency),
            'currency' => $currency,
            'contents' => $contents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public function getOrderData(OrderInterface $order): array
    {
        $currency = (string) ($order->getOrderCurrencyCode() ?: $order->getStoreCurrencyCode());
        $contents = [];

        foreach ($order->getAllVisibleItems() as $item) {
            $contents[] = [
                'id' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'content_type' => 'product',
                'quantity' => (int) $item->getQtyOrdered(),
                'amount' => $this->money->toMinorUnits($item->getPriceInclTax(), $currency),
                'currency' => $currency,
            ];
        }

        return [
            'type' => 'contents',
            'amount' => $this->money->toMinorUnits($order->getGrandTotal(), $currency),
            'currency' => $currency,
            'contents' => $contents,
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    private function mirror(string $event, array $data, string $eventId): void
    {
        if (!$this->config->isApiEventEnabled($event)) {
            return;
        }

        try {
            $this->client->send($event, $data, $eventId);
        } catch (\Exception $e) {
            $this->logger->error('OpenAI Ads: ' . $event . ' server-side call failed: ' . $e->getMessage());
        }
    }

    private function getCurrency(): string
    {
        try {
            return (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        } catch (\Exception $e) {
            return 'EUR';
        }
    }
}
