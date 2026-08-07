<?php

declare(strict_types=1);

namespace NinetyNineX\SwishSuite\Tests\Unit;

use PHPUnit\Framework\TestCase;

/**
 * Minimal stubs to avoid bootstrapping Craft/Commerce.
 */
if (!class_exists(\craft\commerce\models\payments\BasePaymentForm::class)) {
    class_alias(\NinetyNineX\SwishSuite\Tests\Unit\FakeBasePaymentForm::class, \craft\commerce\models\payments\BasePaymentForm::class);
}

class FakeBasePaymentForm
{
    /** @return array<int, array<int|string, mixed>> */
    public function rules(): array
    {
        return [];
    }

    public function validate(array|string|null $attributeNames = null): bool
    {
        $rules  = $this->rules();
        $errors = [];

        foreach ($rules as $rule) {
            $attrs    = (array)$rule[0];
            $ruleName = $rule[1] ?? null;

            foreach ($attrs as $attr) {
                $value = $this->$attr ?? null;

                if ($ruleName === 'match') {
                    if ($value === null || $value === '') {
                        if (!($rule['skipOnEmpty'] ?? false)) {
                            $errors[$attr][] = $rule['message'] ?? 'invalid';
                        }
                    } elseif (!preg_match($rule['pattern'], (string)$value)) {
                        $errors[$attr][] = $rule['message'] ?? 'invalid';
                    }
                }
            }
        }

        return $errors === [];
    }
}

use NinetyNineX\SwishSuite\models\SwishPaymentForm;

class SwishPaymentFormTest extends TestCase
{
    /** @dataProvider validAliasProvider */
    public function test_valid_payer_alias_passes(string $alias): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = $alias;
        self::assertTrue($form->validate(['payerAlias']), "Expected {$alias} to pass");
    }

    /** @dataProvider invalidAliasProvider */
    public function test_invalid_payer_alias_fails(string $alias): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = $alias;
        self::assertFalse($form->validate(['payerAlias']), "Expected {$alias} to fail");
    }

    public function test_null_payer_alias_passes(): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = null;
        self::assertTrue($form->validate(['payerAlias']));
    }

    public function test_empty_payer_alias_passes(): void
    {
        $form = new SwishPaymentForm();
        $form->payerAlias = '';
        self::assertTrue($form->validate(['payerAlias']));
    }

    /** @return array<string, array{string}> */
    public static function validAliasProvider(): array
    {
        return [
            'standard swedish mobile' => ['46701234567'],
            'minimum 9 digits after 46' => ['467012345'],
        ];
    }

    /** @return array<string, array{string}> */
    public static function invalidAliasProvider(): array
    {
        return [
            'missing country code'  => ['0701234567'],
            'wrong country code'    => ['47701234567'],
            'too short'             => ['4670123'],
            'contains letters'      => ['467012345A'],
            'with plus sign'        => ['+46701234567'],
            'with spaces'           => ['467 012 345'],
        ];
    }
}
