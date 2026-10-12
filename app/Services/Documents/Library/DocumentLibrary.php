<?php

namespace App\Services\Documents\Library;

use App\Models\User;
use App\Services\Documents\Library\Definitions\EmployeeRegisterDocument;
use App\Services\Documents\Library\Definitions\HseInspectionRegisterDocument;
use App\Services\Documents\Library\Definitions\ProjectManpowerDocument;
use App\Services\Documents\Library\Definitions\StockPositionDocument;
use RuntimeException;

/**
 * v2.110.0 -- THE DOCUMENT LIBRARY, AS A REGISTRY.
 *
 * The brief asked for a template library across Management, HRD, HSE and
 * Warehouse & Logistics. This is it: a list of registered definitions,
 * each of which the renderer can serve in three formats. Adding a
 * document is one class plus one line here -- no route, no controller, no
 * export class, no Blade view.
 *
 * THE REGISTRY IS CODE, NOT CONFIGURATION, deliberately. A definition
 * carries a tenant-scoped QUERY and an authorization rule; expressing
 * those in a config array would mean either serialised closures or a
 * query language, and a mistake in either becomes a cross-tenant read.
 * A class is also the thing a reviewer can read.
 *
 * Definitions are resolved through the container, so a definition may
 * depend on a service without this class knowing about it.
 */
class DocumentLibrary
{
    /** @var list<class-string<DocumentDefinition>> */
    private const DEFINITIONS = [
        EmployeeRegisterDocument::class,
        HseInspectionRegisterDocument::class,
        StockPositionDocument::class,
        ProjectManpowerDocument::class,
    ];

    /** @return list<DocumentDefinition> */
    public function all(): array
    {
        return array_map(fn (string $class) => app($class), self::DEFINITIONS);
    }

    /**
     * The documents this user may actually read.
     *
     * @return list<DocumentDefinition>
     */
    public function visibleTo(User $user): array
    {
        return array_values(array_filter($this->all(), fn (DocumentDefinition $d) => $d->authorize($user)));
    }

    /**
     * Resolves one document by key, FAILING CLOSED on both the key and
     * the authorization. A caller gets a definition only when the key
     * exists AND this user may read it; an unauthorized key is reported
     * as not found rather than as forbidden, so the library does not
     * confirm the existence of documents a user may not see.
     */
    public function resolveFor(User $user, string $key): DocumentDefinition
    {
        foreach ($this->all() as $definition) {
            if ($definition->key() === $key && $definition->authorize($user)) {
                return $definition;
            }
        }

        throw new RuntimeException('No document is registered under ['.$key.'] for this user.');
    }

    /**
     * The library as the UI needs it, grouped by workspace.
     *
     * @return list<array<string, mixed>>
     */
    public function catalogue(User $user): array
    {
        return array_map(fn (DocumentDefinition $d) => [
            'key' => $d->key(),
            'title' => $d->title(),
            'description' => $d->description(),
            'workspace' => $d->workspace(),
            'formats' => $d->formats(),
            'columns' => array_column($d->columns(), 'label'),
        ], $this->visibleTo($user));
    }
}
