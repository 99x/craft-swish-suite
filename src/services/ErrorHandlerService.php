<?php

namespace NinetyNineX\SwishSuite\services;

use yii\base\Component;

class ErrorHandlerService extends Component
{
    private const ERROR_MESSAGES = [
        'HTTPS_REQUIRED' => 'Swish requer HTTPS para a URL de callback. URL atual: {callbackUrl}',
        'CERT_NOT_FOUND' => 'Arquivo de certificado não encontrado: {certPath}',
        'CA_NOT_FOUND' => 'Arquivo CA não encontrado: {caPath}',
        'CERT_INVALID' => 'Certificado inválido ou corrompido: {certPath}',
        'CERT_PASSWORD_INVALID' => 'Senha do certificado incorreta',
        'API_UNREACHABLE' => 'API do Swish inacessível. Verifique sua conexão e se o certificado está válido',
        'INVALID_CREDENTIALS' => 'Certificado ou número Swish incorreto',
        'INVALID_AMOUNT' => 'Valor inválido. Deve ser um número positivo (ex: 100.50)',
        'INVALID_PAYLOAD' => 'Dados inválidos enviados para o Swish',
        'TIMEOUT' => 'Requisição para Swish expirou. Tente novamente',
        'NETWORK_ERROR' => 'Erro de conexão com a API do Swish',
        'UNKNOWN_ERROR' => 'Erro desconhecido ao processar requisição Swish',
    ];

    public function getErrorMessage(string $errorCode, array $context = []): string
    {
        $template = self::ERROR_MESSAGES[$errorCode] ?? self::ERROR_MESSAGES['UNKNOWN_ERROR'];

        foreach ($context as $key => $value) {
            $template = str_replace('{' . $key . '}', (string)$value, $template);
        }

        return $template;
    }

    public function detectErrorCode(\Throwable $exception): string
    {
        $message = $exception->getMessage();

        if (str_contains($message, 'HTTPS')) {
            return 'HTTPS_REQUIRED';
        }
        if (str_contains($message, 'certificate') || str_contains($message, 'cert')) {
            if (str_contains($message, 'password')) {
                return 'CERT_PASSWORD_INVALID';
            }
            if (str_contains($message, 'not found') || str_contains($message, 'No such file')) {
                return 'CERT_NOT_FOUND';
            }
            return 'CERT_INVALID';
        }
        if (str_contains($message, 'CA') || str_contains($message, 'root')) {
            return 'CA_NOT_FOUND';
        }
        if (str_contains($message, 'timeout') || str_contains($message, 'Timeout')) {
            return 'TIMEOUT';
        }
        if (str_contains($message, 'Connection refused') || str_contains($message, 'Network')) {
            return 'NETWORK_ERROR';
        }
        if (str_contains($message, 'Unauthorized') || str_contains($message, '401')) {
            return 'INVALID_CREDENTIALS';
        }

        return 'UNKNOWN_ERROR';
    }
}
