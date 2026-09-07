<?php
/**
 * Returns the events and the hashed customer data for the current page.
 *
 * Kept out of the rendered HTML on purpose: the storefront page may be served
 * from the full page cache, this response never is.
 *
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Controller\Data;

use Degriz\OpenAiAds\Model\Config;
use Degriz\OpenAiAds\Model\EventBuilder;
use Degriz\OpenAiAds\Model\Identity;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Json;
use Magento\Framework\Controller\Result\JsonFactory;
use Magento\Framework\App\RequestInterface;
use Psr\Log\LoggerInterface;

class Index implements HttpGetActionInterface
{
    public function __construct(
        private readonly Config $config,
        private readonly Identity $identity,
        private readonly EventBuilder $builder,
        private readonly JsonFactory $resultFactory,
        private readonly RequestInterface $request,
        private readonly LoggerInterface $logger
    ) {
    }

    public function execute(): Json
    {
        $result = $this->resultFactory->create();
        $result->setHeader('Cache-Control', 'no-store, no-cache, must-revalidate, max-age=0', true);
        $result->setHeader('Pragma', 'no-cache', true);

        if (!$this->config->isEnabled()) {
            return $result->setData(['events' => []]);
        }

        $context = [
            'page_type' => (string) $this->request->getParam('page_type', ''),
            'product_id' => (int) $this->request->getParam('product_id', 0),
            'query' => (string) $this->request->getParam('query', ''),
            'title' => (string) $this->request->getParam('title', ''),
        ];

        try {
            $events = $this->builder->build($context);
            $user = $this->identity->getPixelUserData();
        } catch (\Exception $e) {
            $this->logger->error('OpenAI Ads: building events failed: ' . $e->getMessage());
            $events = [];
            $user = [];
        }

        $payload = ['events' => []];

        foreach ($events as $event) {
            $options = is_array($event['options'] ?? null) ? $event['options'] : [];
            $options['event_id'] = $event['event_id'];

            $payload['events'][] = [
                'name' => $event['event'],
                'data' => $event['data'],
                'options' => $options,
            ];
        }

        if ($user !== []) {
            $payload['user'] = $user;
        }

        return $result->setData($payload);
    }
}
