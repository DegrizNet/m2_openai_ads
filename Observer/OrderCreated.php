<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Observer;

use Degriz\OpenAiAds\Model\Api\Client;
use Degriz\OpenAiAds\Model\Config;
use Degriz\OpenAiAds\Model\EventBuilder;
use Degriz\OpenAiAds\Model\Identity;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

/**
 * The server side half of the purchase event. The browser sends the same event
 * with the same ID from the success page, and OpenAI keeps only one of them.
 */
class OrderCreated implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly EventBuilder $builder,
        private readonly Client $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        $order = $observer->getEvent()->getData('order');
        if (!$order instanceof OrderInterface || !$order->getIncrementId()) {
            return;
        }

        $storeId = (int) $order->getStoreId();

        if (!$this->config->isEnabled($storeId)
            || !$this->config->isEventEnabled('order_created', $storeId)
            || !$this->config->isApiEventEnabled('order_created', $storeId)
        ) {
            return;
        }

        // Deterministic, so a reload or a retried call never counts twice.
        $eventId = 'order_created-' . $order->getIncrementId();

        try {
            $this->client->send('order_created', $this->builder->getOrderData($order), $eventId, [
                'order' => $order,
                'user' => $this->identity->getApiUserData($order),
            ]);
        } catch (\Exception $e) {
            // Tracking must never break a checkout.
            $this->logger->error('OpenAI Ads: order_created server-side call failed: ' . $e->getMessage());
        }
    }
}
