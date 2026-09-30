<?php

declare(strict_types=1);

namespace Prozharka;

use RuntimeException;

final class PaymentLinkFactory
{
    public function __construct(private readonly Config $config)
    {
    }

    public function isConfigured(): bool
    {
        return trim((string) $this->config->get('PRODAMUS_FORM_URL', '')) !== ''
            && trim((string) $this->config->get('PRODAMUS_SECRET_KEY', '')) !== ''
            && trim((string) $this->config->get('PRODAMUS_SUBSCRIPTION_ID', '')) !== '';
    }

    public function forOrder(array $order, array $customer, string $publicToken): string
    {
        if (!$this->isConfigured()) {
            throw new RuntimeException('Prodamus payment link is not configured');
        }

        $siteBaseUrl = rtrim($this->config->require('SITE_BASE_URL'), '/');
        $formUrl = rtrim($this->config->require('PRODAMUS_FORM_URL'), '/') . '/';
        $data = [
            'do' => 'pay',
            'order_id' => (string) $order['provider_order_id'],
            'customer_name' => (string) $customer['full_name'],
            'customer_phone' => (string) $customer['phone'],
            'customer_email' => (string) $customer['email'],
            'customer_extra' => 'Подписка на проект «Прожарка»',
            'subscription' => $this->config->require('PRODAMUS_SUBSCRIPTION_ID'),
            'sys' => $this->config->get('PRODAMUS_SYSTEM_CODE', 'prozharka'),
            'urlSuccess' => $siteBaseUrl . '/payment.html?order=' . rawurlencode($publicToken),
            'urlReturn' => $siteBaseUrl . '/#subscription',
        ];
        $data['signature'] = ProdamusHmac::sign($data, $this->config->require('PRODAMUS_SECRET_KEY'));

        return $formUrl . '?' . http_build_query($data, '', '&', PHP_QUERY_RFC3986);
    }
}

