<?php

declare(strict_types=1);

namespace App\Services\Fel;

use Illuminate\Support\Facades\Crypt;
use Illuminate\Validation\ValidationException;

/**
 * Certifiers a client company can choose to issue FEL with, and the credentials each one asks for. The choice and the
 * credentials are stored per company (encrypted); emission stays simulated until the certifier's adapter is validated
 * with that certifier's own test account, so a saved choice never produces an untested tax document.
 */
class FelCertifiers
{
    public const NONE = 'none';

    /**
     * @var array<string, array{label: string, fields: list<array{key: string, label: string, secret?: bool, placeholder?: string}>}>
     */
    public const CERTIFIERS = [
        self::NONE => ['label' => 'Selecciona un certificador', 'fields' => []],
        'infile' => ['label' => 'Infile', 'fields' => [
            ['key' => 'user', 'label' => 'Usuario (alias) del certificador'],
            ['key' => 'api_key', 'label' => 'Llave de certificación', 'secret' => true],
            ['key' => 'sign_key', 'label' => 'Llave de firma', 'secret' => true],
        ]],
        'digifact' => ['label' => 'Digifact', 'fields' => [
            ['key' => 'user', 'label' => 'Usuario del certificador'],
            ['key' => 'password', 'label' => 'Contraseña', 'secret' => true],
        ]],
    ];

    public const VAT_AFFILIATIONS = ['GEN' => 'General', 'PEQ' => 'Pequeño contribuyente', 'EXE' => 'Exento'];

    /** @return list<string> */
    public static function codes(): array
    {
        return array_keys(self::CERTIFIERS);
    }

    /**
     * Credentials as they are shown on screen: secrets are masked and never leave the server again.
     *
     * @return array<string, string>
     */
    public function masked(?string $certifier, ?string $encrypted): array
    {
        $stored = $this->decode($encrypted);
        $values = [];

        foreach ((self::CERTIFIERS[$certifier ?? self::NONE]['fields'] ?? []) as $field) {
            $value = (string) ($stored[$field['key']] ?? '');
            $values[$field['key']] = ($field['secret'] ?? false) && $value !== '' ? '••••'.mb_substr($value, -4) : $value;
        }

        return $values;
    }

    /**
     * Encrypted credentials for the chosen certifier; a masked or empty secret keeps the stored one.
     *
     * @param  array<string, mixed>  $input
     */
    public function seal(string $certifier, array $input, ?string $currentEncrypted): ?string
    {
        $fields = self::CERTIFIERS[$certifier]['fields'];

        if ($fields === []) {
            return null;
        }

        $previous = $this->decode($currentEncrypted);
        $credentials = [];
        $errors = [];

        foreach ($fields as $field) {
            $value = trim((string) ($input[$field['key']] ?? ''));
            $keepsStored = ($field['secret'] ?? false) && ($value === '' || str_starts_with($value, '••••'));
            $value = $keepsStored ? (string) ($previous[$field['key']] ?? '') : $value;

            if ($value === '') {
                $errors['credentials.'.$field['key']] = $field['label'].' es obligatorio para conectar con '.self::CERTIFIERS[$certifier]['label'].'.';
            }

            $credentials[$field['key']] = mb_substr($value, 0, 255);
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }

        return Crypt::encryptString(json_encode($credentials, JSON_THROW_ON_ERROR));
    }

    /** @return array<string, string> */
    public function decode(?string $encrypted): array
    {
        if ($encrypted === null || $encrypted === '') {
            return [];
        }

        return json_decode(Crypt::decryptString($encrypted), true, flags: JSON_THROW_ON_ERROR) ?: [];
    }
}
