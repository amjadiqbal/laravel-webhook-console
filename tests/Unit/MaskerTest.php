<?php

use AmjadIqbal\WebhookConsole\Masker;

beforeEach(function () {
    $this->masker = new Masker(['password', 'secret', 'token', 'authorization', 'signature', 'api_key', 'card_number'], ['Stripe-Signature']);
});

it('masks secret payload keys at any depth and keeps the rest', function () {
    $masked = $this->masker->payload([
        'type' => 'invoice.paid',
        'api_key' => 'sk_live_123',
        'data' => ['object' => ['customer' => 'cus_1', 'client_secret' => 'pi_secret', 'tokens' => ['a', 'b']]],
        'empty_token' => '',
    ]);

    expect($masked['type'])->toBe('invoice.paid')
        ->and($masked['api_key'])->toBe(Masker::MASK)
        ->and($masked['data']['object']['customer'])->toBe('cus_1')
        ->and($masked['data']['object']['client_secret'])->toBe(Masker::MASK)
        ->and($masked['data']['object']['tokens'])->toBe([Masker::MASK, Masker::MASK])
        ->and($masked['empty_token'])->toBe('');
});

it('masks secret headers and the configured signature header', function () {
    $headers = $this->masker->headers([
        'stripe-signature' => ['t=1,v1=abc'],
        'authorization' => ['Bearer xyz'],
        'content-type' => ['application/json'],
    ]);

    expect($headers['stripe-signature'])->toBe([Masker::MASK])
        ->and($headers['authorization'])->toBe([Masker::MASK])
        ->and($headers['content-type'])->toBe(['application/json']);
});

it('masks secret query parameters in the URL', function () {
    expect($this->masker->url('https://app.test/webhooks?token=abc&shop=demo&api_key=1'))
        ->toBe('https://app.test/webhooks?token='.Masker::MASK.'&shop=demo&api_key='.Masker::MASK)
        ->and($this->masker->url('https://app.test/webhooks'))->toBe('https://app.test/webhooks');
});

it('shows attachment metadata but never the stored file content', function () {
    $files = $this->masker->attachments([['originalName' => 'a.pdf', 'mimeType' => 'application/pdf', 'size' => 12, 'content' => base64_encode('secret file'), 'path' => '/tmp/x']]);

    expect($files)->toBe([['name' => 'a.pdf', 'type' => 'application/pdf', 'size' => 12]]);

    $legacy = $this->masker->payload(['attachments' => [['originalName' => 'a.pdf', 'content' => base64_encode('secret file')]]]);
    expect($legacy['attachments'][0]['content'])->toBe('(16 base64 characters hidden)');
});
