<?php

namespace Modules\WhatsAppVendorConcierge\app\Services;

use App\Services\RegistrationPolicyService;

class FlowDefinitionValidator
{
    public function path(): string
    {
        return module_path('WhatsAppVendorConcierge', 'resources/flows/vendor_onboarding.json');
    }

    public function validate(?array $definition = null): array
    {
        $f = $definition ?? json_decode(file_get_contents($this->path()), true, 64, JSON_THROW_ON_ERROR);
        $schema = json_decode(file_get_contents(dirname($this->path()).'/vendor_onboarding.schema.json'), true, 32, JSON_THROW_ON_ERROR);
        $this->schema($f, $schema);
        if (($f['version'] ?? '') !== '7.3' || ($f['data_api_version'] ?? '') !== '3.0') {
            throw new \InvalidArgumentException('Unsupported verified Flow schema/protocol.');
        }
        if (array_column($f['screens'] ?? [], 'id') !== FlowDataExchangeService::SCREENS) {
            throw new \InvalidArgumentException('Flow screen contract mismatch.');
        }
        foreach ($f['screens'] as $s) {
            if (array_key_exists('sensitive', $s) && (! is_array($s['sensitive']) || ! array_is_list($s['sensitive']) || $s['sensitive'] === [])) {
                throw new \InvalidArgumentException('An optional sensitive field list must be non-empty.');
            }
            if (($s['layout']['type'] ?? '') !== 'SingleColumnLayout') {
                throw new \InvalidArgumentException('Invalid layout.');
            }
            $nodes = $this->flatten($s['layout']['children'] ?? []);
            $pickers = array_filter($nodes, fn ($n) => in_array($n['type'] ?? '', ['PhotoPicker', 'DocumentPicker'], true));
            if (count($nodes) > 50 || count($pickers) > 1) {
                throw new \InvalidArgumentException('Meta component limit exceeded.');
            }
            $names = array_values(array_filter(array_column($nodes, 'name')));
            if (count($names) !== count(array_unique($names))) {
                throw new \InvalidArgumentException('Duplicate form field names.');
            }
            foreach ($nodes as $n) {
                if (($n['type'] ?? '') === 'OptIn') {
                    $statements = ['terms_agreed' => RegistrationPolicyService::TERMS, 'privacy_acknowledged' => RegistrationPolicyService::PRIVACY];
                    if (($statements[$n['name'] ?? ''] ?? null) !== ($n['label'] ?? null) || ($n['required'] ?? null) !== true || ($n['init-value'] ?? null) !== false) {
                        throw new \InvalidArgumentException('Policy presentation differs from the server-owned evidence contract.');
                    }
                }
                if (isset($n['label']) && in_array($n['type'], ['TextInput', 'TextArea', 'Dropdown'], true) && mb_strlen($n['label']) > 20) {
                    throw new \InvalidArgumentException('Component label limit exceeded.');
                }
                $encoded = json_encode($n, JSON_THROW_ON_ERROR);
                preg_match_all('/\$\{(form|data)\.([a-z_]+)\}/', $encoded, $references, PREG_SET_ORDER);
                foreach ($references as $ref) {
                    if (($ref[1] === 'data' && ! isset($s['data'][$ref[2]])) || ($ref[1] === 'form' && ! in_array($ref[2], $names, true))) {
                        throw new \InvalidArgumentException('Unresolved Flow field reference.');
                    }
                }
                if (! in_array($n['type'] ?? '', ['TextBody', 'TextCaption', 'TextInput', 'TextArea', 'Dropdown', 'CheckboxGroup', 'If', 'PhotoPicker', 'DocumentPicker', 'OptIn', 'EmbeddedLink', 'Footer'], true)) {
                    throw new \InvalidArgumentException('Unsupported component.');
                }
                if (($n['type'] ?? '') === 'Footer' && ($n['on-click-action']['name'] ?? '') !== 'data_exchange') {
                    throw new \InvalidArgumentException('Endpoint navigation is required.');
                }
            }
            if (! array_filter($nodes, fn ($n) => ($n['type'] ?? '') === 'Footer')) {
                throw new \InvalidArgumentException('Screen footer missing.');
            }
            foreach ($s['data'] as $d) {
                if (! array_key_exists('__example__', $d)) {
                    throw new \InvalidArgumentException('Dynamic data example missing.');
                }
            }
        }

        return $f;
    }

    /** Validate every keyword used by our deliberately small local JSON Schema. */
    private function schema(mixed $value, array $schema): void
    {
        if (isset($schema['type'])) {
            $valid = match ($schema['type']) {
                'object' => is_array($value) && ($value === [] || ! array_is_list($value)),'array' => is_array($value) && array_is_list($value),default => false
            };
            if (! $valid) {
                throw new \InvalidArgumentException('Flow JSON Schema type mismatch.');
            }
        }
        if (array_key_exists('const', $schema) && $value !== $schema['const']) {
            throw new \InvalidArgumentException('Flow JSON Schema constant mismatch.');
        }
        if (isset($schema['enum']) && ! in_array($value, $schema['enum'], true)) {
            throw new \InvalidArgumentException('Flow JSON Schema enum mismatch.');
        }
        foreach ($schema['required'] ?? [] as $key) {
            if (! array_key_exists($key, $value)) {
                throw new \InvalidArgumentException('Missing required Flow schema property.');
            }
        }
        if (isset($schema['minItems']) && count($value) < $schema['minItems'] || isset($schema['maxItems']) && count($value) > $schema['maxItems']) {
            throw new \InvalidArgumentException('Flow JSON Schema item limit mismatch.');
        }
        foreach ($schema['properties'] ?? [] as $key => $property) {
            if (array_key_exists($key, $value)) {
                $this->schema($value[$key], $property);
            }
        }
        if (isset($schema['items'])) {
            foreach ($value as $item) {
                $this->schema($item, $schema['items']);
            }
        }
    }

    private function flatten(array $nodes): array
    {
        $out = [];
        foreach ($nodes as $n) {
            $out[] = $n;
            foreach (['children', 'then', 'else'] as $key) {
                if (isset($n[$key])) {
                    $out = array_merge($out, $this->flatten($n[$key]));
                }
            }
        }

        return $out;
    }
}
