<?php

namespace Saola\Permission\Services;

use LogicException;
use Saola\Core\Routing\Permission;
use Saola\Core\System;
use Saola\Permission\Data\PermissionRequirement;

class PermissionRegistry
{
    /** @return list<array{context:string,module_path:array,operation:string}> */
    public function catalog(?string $context = null): array
    {
        return array_map(fn ($row) => PermissionRequirement::fromArray($row)->toArray(), System::permissionDeclarations($context));
    }

    /** A projection of System metadata; it never mutates the route tree. */
    public function matrix(?string $context = null): array
    {
        $rows = [];
        foreach (System::getContexts() as $slug => $node) {
            if ($context === null || $context === $slug) {
                $this->walk($node->toArray(), $slug, [], [], '', $rows);
            }
        }

        return $rows;
    }

    public function forRoute(?string $name, string $method): array
    {
        $matches = array_values(array_filter($this->matrix(), fn ($row) => $name !== null && $name !== ''
            && $row['route_name'] === $name && in_array(strtoupper($method), $row['methods'], true)));
        if (count($matches) !== 1) {
            throw new LogicException('Route permission middleware requires a unique, named System action: '.($name ?? '(unnamed)'));
        }

        return $matches[0];
    }

    private function walk(array $node, string $context, array $modules, array $inherited, string $prefix, array &$rows): void
    {
        $type = $node['type'] ?? 'router';
        $container = in_array($type, ['context', 'module', 'submodule', 'sub', 'group', 'channel'], true);
        if (in_array($type, ['module', 'submodule', 'sub'], true)) {
            $modules[] = $node['slug'];
            // Grant scope never expands to descendants. Only ACCESS barriers cross a module boundary.
            $inherited = array_values(array_filter($inherited, fn ($row) => $row['operation'] === Permission::ACCESS->value));
        }
        $declared = $node['permission'] ?? [];
        $required = $inherited;
        foreach ($declared as $operation) {
            $required[] = (new PermissionRequirement($context, $modules, Permission::from($operation)))->toArray();
        }
        $required = $this->unique($required);
        if ($container) {
            $prefix = trim($prefix.'/'.trim($node['prefix'] ?? '', '/'), '/');
            foreach ($node['children'] ?? [] as $child) {
                $this->walk($child, $context, $modules, $required, $prefix, $rows);
            }

            return;
        }

        $methods = ! empty($node['is_match']) ? ($node['match'] ?? []) : [$node['method'] ?? 'get'];
        $methods = array_map('strtoupper', $methods);
        if (in_array('ANY', $methods, true)) {
            $methods = ['GET', 'HEAD', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'];
        }
        if (in_array('GET', $methods, true)) {
            $methods[] = 'HEAD';
        }
        $rows[] = [
            'context' => $context,
            'module_path' => $modules,
            'route_name' => $node['as'] ?? null,
            'methods' => array_values(array_unique($methods)),
            'prefix' => $prefix,
            'uri' => '/'.trim($prefix.'/'.trim($node['uri'] ?? '', '/'), '/'),
            'declared_permissions' => $declared,
            'inherited_permissions' => $inherited,
            'required_permissions' => $required,
        ];
    }

    private function unique(array $rows): array
    {
        $unique = [];
        foreach ($rows as $row) {
            $unique[PermissionRequirement::fromArray($row)->key()] = $row;
        }

        return array_values($unique);
    }
}
