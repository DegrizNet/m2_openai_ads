<?php
/**
 * @author  Anze Voh - Degriz <https://www.degriz.net/>
 * @license MIT
 */
declare(strict_types=1);

namespace Degriz\OpenAiAds\Model\Config\Source;

use Degriz\OpenAiAds\Model\Config;
use Magento\Framework\Data\OptionSourceInterface;

/**
 * The events that may be mirrored to the Conversions API.
 */
class Events implements OptionSourceInterface
{
    /**
     * @return array<int, array<string, string>>
     */
    public function toOptionArray(): array
    {
        $options = [];

        foreach (array_keys(Config::EVENT_TYPES) as $event) {
            $options[] = ['value' => $event, 'label' => $event];
        }

        return $options;
    }
}
