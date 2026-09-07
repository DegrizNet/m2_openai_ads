<?php
/**
 * Carries events across a redirect.
 *
 * Add to cart, registration and newsletter sign-up all end in a redirect, so
 * there is no page left to fire on. The event waits here for the next request.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model;

use Magento\Framework\Math\Random;
use Magento\Framework\Session\SessionManagerInterface;

class EventQueue
{
    private const KEY = 'degriz_openaiads_queue';

    public function __construct(
        private readonly SessionManagerInterface $session,
        private readonly Random $random
    ) {
    }

    /**
     * @param array<string, mixed> $data
     * @param array<string, mixed> $options
     */
    public function add(string $event, array $data, ?string $eventId = null, array $options = []): void
    {
        $queue = $this->session->getData(self::KEY);
        if (!is_array($queue)) {
            $queue = [];
        }

        $queue[] = [
            'event' => $event,
            'data' => $data,
            'event_id' => $eventId ?: $this->generateEventId($event),
            'options' => $options,
        ];

        $this->session->setData(self::KEY, $queue);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function take(): array
    {
        $queue = $this->session->getData(self::KEY);
        $this->session->unsetData(self::KEY);

        return is_array($queue) ? $queue : [];
    }

    /**
     * Browser and server must agree on this value or the conversion counts twice.
     */
    public function generateEventId(string $event): string
    {
        return $event . '-' . $this->random->getRandomString(24);
    }

    public function getFlag(string $key): mixed
    {
        return $this->session->getData('degriz_openaiads_' . $key);
    }

    public function setFlag(string $key, mixed $value): void
    {
        $this->session->setData('degriz_openaiads_' . $key, $value);
    }
}
