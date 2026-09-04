<?php

declare(strict_types=1);

namespace Firefly\Admin;

/**
 * Builds the node set and resolves every declared dependency onto it.
 *
 * Split out of BeanGraph because indexing and layering are genuinely different jobs, and the indexing half
 * carries all of the subtlety: which of three node kinds a row becomes, how a #[Bean] method's PRODUCT gets
 * an identity of its own, and how a dependency on an interface finds the concrete thing that satisfies it.
 *
 * IDENTITY OF A #[Bean] PRODUCT. Usually the produced TYPE is the identity, because that is the key the
 * container binds and the key every consumer asks for. When two factory methods produce the same type — the
 * shape ContainerRegistrar now requires #[Primary]/#[Qualifier] to disambiguate — the type alone would
 * collapse them into one node and hide exactly the ambiguity the reader came to look at, so each competitor
 * gets `Declaring::method()` as its id and the type is recorded on the node for the label.
 */
final class BeanGraphIndex
{
    /** @var array<string, array{id: string, label: string, namespace: string, kind: string, stereotype: string, scope: string, detail: string}> */
    private array $nodes = [];

    /** @var array<string, string> interface or produced type => the node id that satisfies it */
    private array $satisfiedBy = [];

    /** @var list<array{from: string, dependencies: list<string>, type: string}> */
    private array $pending = [];

    /** @var array<string, int> how many factory methods produce each type */
    private array $producerCount = [];

    /**
     * @param  array<mixed>  $row
     */
    public function addComponent(array $row): void
    {
        /** @var string $class */
        $class = $row['class'];

        $this->put($class, [
            'id' => $class,
            'label' => Format::shortClass($class),
            'namespace' => rtrim(Format::namespaceOf($class), '\\'),
            'kind' => BeanGraph::KIND_COMPONENT,
            'stereotype' => is_string($row['stereotype'] ?? null) ? $row['stereotype'] : '',
            'scope' => is_string($row['scope'] ?? null) ? $row['scope'] : '',
            'detail' => '',
        ]);

        foreach ($this->strings($row['interfaces'] ?? null) as $interface) {
            $this->satisfy($interface, $class);
        }

        $this->pending[] = [
            'from' => $class,
            'dependencies' => $this->strings($row['dependencies'] ?? null),
            'type' => BeanGraph::EDGE_INJECTS,
        ];

        foreach ($this->producers($row['produces'] ?? null) as $produced) {
            $this->producerCount[$produced['type']] = ($this->producerCount[$produced['type']] ?? 0) + 1;
        }

        foreach ($this->producers($row['produces'] ?? null) as $produced) {
            $this->addBean($class, $produced);
        }
    }

    public function addConfigProperties(string $class): void
    {
        // A #[ConfigProperties] DTO is bound and injectable but is neither scanned as a component nor
        // produced by a factory, so nothing else here would ever create a node for it — which is why
        // `App\GreetingProperties` showed up as an unresolved dependency of GreetingService rather than as
        // the bean it is.
        $this->put($class, [
            'id' => $class,
            'label' => Format::shortClass($class),
            'namespace' => rtrim(Format::namespaceOf($class), '\\'),
            'kind' => BeanGraph::KIND_CONFIG,
            'stereotype' => 'config-properties',
            'scope' => 'Singleton',
            'detail' => 'bound from configuration',
        ]);

        $this->satisfy($class, $class);
    }

    /**
     * @param  array{type: string, method: string, dependencies: list<string>}  $produced
     */
    private function addBean(string $declaring, array $produced): void
    {
        $contested = ($this->producerCount[$produced['type']] ?? 0) > 1;
        $id = $contested ? $declaring.'::'.$produced['method'].'()' : $produced['type'];

        $this->put($id, [
            'id' => $id,
            'label' => Format::shortClass($produced['type']),
            'namespace' => rtrim(Format::namespaceOf($produced['type']), '\\'),
            'kind' => BeanGraph::KIND_BEAN,
            'stereotype' => 'bean',
            'scope' => 'Singleton',
            'detail' => Format::shortClass($declaring).'::'.$produced['method'].'()',
        ]);

        // The produced type resolves to this node. With competitors, first-writer-wins gives the bare type a
        // stable owner while each competitor keeps its own node — the same shape the container itself has,
        // where the type key aliases the #[Primary] winner and every candidate stays reachable by name.
        $this->satisfy($produced['type'], $id);

        $this->pending[] = ['from' => $declaring, 'dependencies' => [$id], 'type' => BeanGraph::EDGE_PRODUCES];
        $this->pending[] = ['from' => $id, 'dependencies' => $produced['dependencies'], 'type' => BeanGraph::EDGE_INJECTS];
    }

    /** @return list<string> */
    public function ids(): array
    {
        return array_keys($this->nodes);
    }

    /** @return array<string, array{id: string, label: string, namespace: string, kind: string, stereotype: string, scope: string, detail: string}> */
    public function nodes(): array
    {
        return $this->nodes;
    }

    /**
     * Every declared dependency resolved onto the node set, plus the types nothing here provides.
     *
     * An unresolved type is reported rather than dropped: it is almost always a Laravel container binding
     * (the Request, the config repository, a database connection) rather than a bean, and "why is my bean
     * not in the graph" is exactly the question this page exists to answer.
     *
     * @return array{0: list<array{from: string, to: string, via: string|null, type: string}>, 1: list<string>}
     */
    public function edges(): array
    {
        $edges = [];
        $seen = [];
        $unresolved = [];

        foreach ($this->pending as $entry) {
            foreach ($entry['dependencies'] as $dependency) {
                $target = $this->resolve($dependency);

                if ($target === null) {
                    $unresolved[] = $dependency;

                    continue;
                }

                if ($target === $entry['from']) {
                    continue;
                }

                $key = $entry['from'].'>'.$target.'>'.$entry['type'];
                if (isset($seen[$key])) {
                    continue;
                }
                $seen[$key] = true;

                $edges[] = [
                    'from' => $entry['from'],
                    'to' => $target,
                    'via' => $target === $dependency ? null : $dependency,
                    'type' => $entry['type'],
                ];
            }
        }

        return [$edges, array_values(array_unique($unresolved))];
    }

    private function resolve(string $type): ?string
    {
        return isset($this->nodes[$type]) ? $type : ($this->satisfiedBy[$type] ?? null);
    }

    /** First writer wins, so the same application always draws the same graph. */
    private function satisfy(string $type, string $nodeId): void
    {
        $this->satisfiedBy[$type] ??= $nodeId;
    }

    /**
     * @param  array{id: string, label: string, namespace: string, kind: string, stereotype: string, scope: string, detail: string}  $node
     */
    private function put(string $id, array $node): void
    {
        $this->nodes[$id] ??= $node;
    }

    /**
     * @return list<array{type: string, method: string, dependencies: list<string>}>
     */
    private function producers(mixed $produces): array
    {
        if (! is_array($produces)) {
            return [];
        }

        $out = [];
        foreach ($produces as $entry) {
            if (! is_array($entry) || ! is_string($entry['type'] ?? null) || $entry['type'] === '') {
                continue;
            }

            $out[] = [
                'type' => $entry['type'],
                'method' => is_string($entry['method'] ?? null) ? $entry['method'] : 'bean',
                'dependencies' => $this->strings($entry['dependencies'] ?? null),
            ];
        }

        return $out;
    }

    /** @return list<string> */
    private function strings(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
