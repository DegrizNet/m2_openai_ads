<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Observer;

use Degriz\OpenAiAds\Model\Api\Client;
use Degriz\OpenAiAds\Model\Config;
use Degriz\OpenAiAds\Model\EventQueue;
use Degriz\OpenAiAds\Model\Money;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;

class ItemsAdded implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly EventQueue $queue,
        private readonly Money $money,
        private readonly Client $client,
        private readonly StoreManagerInterface $storeManager,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isEventEnabled('items_added')) {
            return;
        }

        $item = $observer->getEvent()->getData('quote_item');
        if (!$item) {
            return;
        }

        if ($item->getParentItem()) {
            $item = $item->getParentItem();
        }

        $currency = (string) $this->storeManager->getStore()->getCurrentCurrencyCode();
        $qty = (int) $item->getQty() ?: 1;
        $price = (float) ($item->getPriceInclTax() ?: $item->getPrice());

        $data = [
            'type' => 'contents',
            'amount' => $this->money->toMinorUnits($price * $qty, $currency),
            'currency' => $currency,
            'contents' => [[
                'id' => (string) $item->getSku(),
                'name' => (string) $item->getName(),
                'content_type' => 'product',
                'quantity' => $qty,
                'amount' => $this->money->toMinorUnits($price, $currency),
                'currency' => $currency,
            ]],
        ];

        $eventId = $this->queue->generateEventId('items_added');
        $this->queue->add('items_added', $data, $eventId);

        if (!$this->config->isApiEventEnabled('items_added')) {
            return;
        }

        try {
            $this->client->send('items_added', $data, $eventId);
        } catch (\Exception $e) {
            $this->logger->error('OpenAI Ads: items_added server-side call failed: ' . $e->getMessage());
        }
    }
}
