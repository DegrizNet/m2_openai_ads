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
use Degriz\OpenAiAds\Model\Identity;
use Magento\Framework\Event\Observer;
use Magento\Framework\Event\ObserverInterface;
use Magento\Newsletter\Model\Subscriber;
use Psr\Log\LoggerInterface;

class LeadCreated implements ObserverInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly EventQueue $queue,
        private readonly Client $client,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(Observer $observer): void
    {
        if (!$this->config->isEnabled() || !$this->config->isEventEnabled('lead_created')) {
            return;
        }

        $subscriber = $observer->getEvent()->getData('subscriber');
        if (!$subscriber instanceof Subscriber) {
            return;
        }

        if ((int) $subscriber->getStatus() !== Subscriber::STATUS_SUBSCRIBED) {
            return;
        }

        // save_after also fires on unrelated updates; only a fresh subscription counts.
        if ((int) $subscriber->getOrigData('subscriber_status') === Subscriber::STATUS_SUBSCRIBED) {
            return;
        }

        $data = ['type' => 'customer_action'];
        $eventId = $this->queue->generateEventId('lead_created');

        $this->queue->add('lead_created', $data, $eventId);

        if (!$this->config->isApiEventEnabled('lead_created')) {
            return;
        }

        $user = $this->identity->getApiUserData();
        $hash = $this->identity->hashEmail((string) $subscriber->getSubscriberEmail());
        if ($hash !== null) {
            $user['emails_sha256'] = [$hash];
        }

        try {
            $this->client->send('lead_created', $data, $eventId, ['user' => $user]);
        } catch (\Exception $e) {
            $this->logger->error('OpenAI Ads: lead_created server-side call failed: ' . $e->getMessage());
        }
    }
}
