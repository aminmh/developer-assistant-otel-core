<?php

declare(strict_types=1);

namespace Indexr\Otel\Core;

/** Stable, framework-neutral version 1 static application description. */
final class IntrospectionDocument
{
    /** @param list<array<string, mixed>> $routes
     *  @param list<array<string, mixed>> $services
     *  @param list<array<string, mixed>> $listeners
     *  @param list<array<string, mixed>> $scheduledTasks
     *  @param list<array<string, mixed>> $queueBindings
     */
    public function __construct(
        public readonly array $routes = [],
        public readonly array $services = [],
        public readonly array $listeners = [],
        public readonly array $scheduledTasks = [],
        public readonly array $queueBindings = [],
    ) {
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'schema_version' => 1,
            'routes' => $this->routes,
            'services' => $this->services,
            'listeners' => $this->listeners,
            'scheduled_tasks' => $this->scheduledTasks,
            'queue_bindings' => $this->queueBindings,
        ];
    }

    public function toJson(): string
    {
        return json_encode($this->toArray(), JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
    }
}
