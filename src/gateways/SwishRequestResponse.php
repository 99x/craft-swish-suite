<?php

namespace NinetyNineX\SwishSuite\gateways;

use craft\commerce\base\RequestResponseInterface;

class SwishRequestResponse implements RequestResponseInterface
{
    public function __construct(
        private readonly bool   $success,
        private readonly bool   $processing,
        private readonly bool   $redirect,
        private readonly string $redirectUrl = '',
        private readonly string $reference   = '',
        private readonly string $code        = '',
        private readonly string $message     = '',
        private readonly mixed  $data        = null,
    ) {}

    public function isSuccessful(): bool     { return $this->success; }
    public function isProcessing(): bool     { return $this->processing; }
    public function isRedirect(): bool       { return $this->redirect; }
    public function getRedirectUrl(): string { return $this->redirectUrl; }
    public function getTransactionReference(): string { return $this->reference; }
    public function getCode(): string        { return $this->code; }
    public function getMessage(): string     { return $this->message; }
    public function getData(): mixed         { return $this->data; }
    public function getRequest(): mixed      { return null; }

    public function redirect(): void
    {
        if ($this->redirectUrl !== '') {
            \Craft::$app->getResponse()->redirect($this->redirectUrl)->send();
            exit;
        }
    }

    public static function asRedirect(string $url, string $paymentId): self
    {
        return new self(
            success:     false,
            processing:  true,
            redirect:    true,
            redirectUrl: $url,
            reference:   $paymentId,
            code:        'REDIRECT',
            message:     'Redirecting to Swish payment screen.',
        );
    }

    public static function asSuccess(string $reference, string $message = ''): self
    {
        return new self(
            success:    true,
            processing: false,
            redirect:   false,
            reference:  $reference,
            code:       'SUCCESS',
            message:    $message,
        );
    }

    public static function asFailure(string $message, string $code = 'ERROR'): self
    {
        return new self(
            success:    false,
            processing: false,
            redirect:   false,
            code:       $code,
            message:    $message,
        );
    }
}
