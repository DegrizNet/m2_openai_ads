<?php
/**
 * Conversions API client.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model\Api;

use Degriz\OpenAiAds\Model\Config;
use Degriz\OpenAiAds\Model\Identity;
use Magento\Framework\HTTP\Client\CurlFactory;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Framework\UrlInterface;
use Magento\Sales\Api\Data\OrderInterface;
use Psr\Log\LoggerInterface;

class Client
{
    private const ENDPOINT = 'https://bzr.openai.com/v1/events';

    /** Batches are capped at 1000 events and one bad event fails the whole batch. */
    private const MAX_BATCH = 1000;

    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly CurlFactory $curlFactory,
        private readonly Json $json,
        private readonly UrlInterface $url,
        private readonly LoggerInterface $logger
    ) {
    }

    /**
     * @param array<string, mixed> $data    the data object (type, amount, currency, contents)
     * @param array<string, mixed> $options user, source_url, action_source, custom_event_name, order
     */
    public function send(string $event, array $data, string $eventId, array $options = []): bool
    {
        if (!$this->config->isApiEnabled() || !$this->identity->hasConsent()) {
            return false;
        }

        $payload = [
            'id' => $eventId,
            'type' => $event,
            'timestamp_ms' => (int) round(microtime(true) * 1000),
            'action_source' => $options['action_source'] ?? 'web',
            'data' => $data,
        ];

        $sourceUrl = $options['source_url'] ?? $this->url->getCurrentUrl();
        if ($sourceUrl) {
            $payload['source_url'] = $sourceUrl;
        }

        if ($event === 'custom' && !empty($options['custom_event_name'])) {
            $payload['custom_event_name'] = $options['custom_event_name'];
        }

        $oppref = $this->identity->getOppref();
        if ($oppref !== null) {
            $payload['oppref'] = $oppref;
        }

        $order = $options['order'] ?? null;
        $user = $options['user'] ?? $this->identity->getApiUserData(
            $order instanceof OrderInterface ? $order : null
        );

        if (!empty($user)) {
            $payload['user'] = $user;
        }

        return $this->sendBatch([$payload]);
    }

    /**
     * @param array<int, array<string, mixed>> $events already shaped event payloads
     */
    public function sendBatch(array $events): bool
    {
        if ($events === []) {
            return false;
        }

        $pixelId = $this->config->getPixelId();
        $apiKey = $this->config->getApiKey();

        if ($pixelId === '' || $apiKey === '') {
            return false;
        }

        $body = [
            'validate_only' => $this->config->isValidateOnly(),
            'integration_source' => 'degriz-magento2',
            'events' => array_slice($events, 0, self::MAX_BATCH),
        ];

        // A fresh client per call, so headers never leak between requests.
        $curl = $this->curlFactory->create();

        try {
            $curl->setTimeout($this->config->getApiTimeout());
            $curl->addHeader('Authorization', 'Bearer ' . $apiKey);
            $curl->addHeader('Content-Type', 'application/json');
            $curl->post(
                self::ENDPOINT . '?pid=' . rawurlencode($pixelId),
                $this->json->serialize($body)
            );

            $status = $curl->getStatus();
        } catch (\Exception $e) {
            $this->logger->error('OpenAI Ads: Conversions API call failed: ' . $e->getMessage());

            return false;
        }

        if ($status < 200 || $status >= 300) {
            $this->logger->error(
                'OpenAI Ads: Conversions API returned HTTP ' . $status . ': ' . $curl->getBody()
            );

            return false;
        }

        if ($this->config->isDebug()) {
            $this->logger->debug('OpenAI Ads: Conversions API OK: ' . $this->json->serialize($body));
        }

        return true;
    }
}
