<?php

namespace Saola\Permission\Data;

use InvalidArgumentException;
use Saola\Core\Routing\Permission;

/** A grant is identified by context + exact module path + a shared operation. */
final readonly class PermissionRequirement
{
    public array $modulePath;

    public function __construct(public string $context, array $modulePath, public Permission $operation)
    {
        if (trim($context) === '' || strlen($context) > 64) {
            throw new InvalidArgumentException('Permission context must contain 1 to 64 bytes.');
        }
        foreach ($modulePath as $segment) {
            if (! is_string($segment) || trim($segment) === '') {
                throw new InvalidArgumentException('Module path segments must be non-empty strings.');
            }
        }
        $this->modulePath = array_values($modulePath);
        if (strlen($this->encodedPath()) > 512) {
            throw new InvalidArgumentException('Encoded module path exceeds 512 bytes.');
        }
    }

    /** Decode serialized metadata, never used to accept string declarations. */
    public static function fromArray(array $data): self
    {
        if (! isset($data['context'], $data['module_path'], $data['operation'])
            || ! is_string($data['context']) || ! is_array($data['module_path']) || ! is_string($data['operation'])) {
            throw new InvalidArgumentException('Invalid serialized permission requirement.');
        }

        return new self($data['context'], $data['module_path'], Permission::from($data['operation']));
    }

    public function toArray(): array
    {
        return ['context' => $this->context, 'module_path' => $this->modulePath, 'operation' => $this->operation->value];
    }

    public function storageAttributes(): array
    {
        return ['scope_key' => hash('sha256', $this->key()), 'context' => $this->context, 'module_path' => $this->encodedPath(), 'operation' => $this->operation->value];
    }

    public function key(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR);
    }

    public function encodedPath(): string
    {
        return json_encode($this->modulePath, JSON_THROW_ON_ERROR);
    }
}
