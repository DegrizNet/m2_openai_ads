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
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Psr\Log\LoggerInterface;

class RegistrationCompleted implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly EventQueue $queue,
        private readonly Client $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isEventEnabled('registration_completed')) {
            return;
        }

        $data = ['type' => 'customer_action'];
        $eventId = $this->queue->generateEventId('registration_completed');

        $this->queue->add('registration_completed', $data, $eventId);

        if (!$this->config->isApiEventEnabled('registration_completed')) {
            return;
        }

        try {
            $this->client->send('registration_completed', $data, $eventId);
        } catch (\Exception $e) {
            $this->logger->error(
                'OpenAI Ads: registration_completed server-side call failed: ' . $e->getMessage()
            );
        }
    }
}
