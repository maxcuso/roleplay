<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

use Flarum\User\User;
use Illuminate\Database\ConnectionInterface;
use Maxcuso\Roleplay\Api\Access;
use Maxcuso\Roleplay\Api\ApiProblem;
use Maxcuso\Roleplay\Catalog\Attributes\AttributeCatalog;
use Maxcuso\Roleplay\Catalog\Races\RaceCatalog;
use Maxcuso\Roleplay\Catalog\MagicPaths\MagicPathCatalog;
use Maxcuso\Roleplay\Catalog\ItemTypes\ItemTypeCatalog;
use Maxcuso\Roleplay\Catalog\PerkSources\PerkSourceCatalog;
use Maxcuso\Roleplay\Catalog\Perks\PerkCatalog;
use Maxcuso\Roleplay\Catalog\Spells\SpellCatalog;
use Maxcuso\Roleplay\Catalog\Items\ItemCatalog;
use Maxcuso\Roleplay\Catalog\Skills\SkillCatalog;
use Maxcuso\Roleplay\Catalog\Skills\SkillLevelStore;
use Maxcuso\Roleplay\Catalog\Proficiencies\ProficiencyCatalog;
use Maxcuso\Roleplay\Catalog\Ranks\RankCatalog;

/** Новый раздел подключается здесь. Имя класса и таблицы никогда не берётся из запроса. */
final class CatalogRegistry
{
    private const SERVICES = [
        'attributes' => [AttributeCatalog::class, 'attributes'],
        'races' => [RaceCatalog::class, 'races'],
        'paths' => [MagicPathCatalog::class, 'magic_paths'],
        'item-types' => [ItemTypeCatalog::class, 'item_types'],
        'sources' => [PerkSourceCatalog::class, 'perk_sources'],
        'perks' => [PerkCatalog::class, 'perks'],
        'spells' => [SpellCatalog::class, 'spells'],
        'items' => [ItemCatalog::class, 'items'],
        'skills' => [SkillCatalog::class, 'skills'],
        'proficiencies' => [ProficiencyCatalog::class, 'weapon_proficiencies'],
        'ranks' => [RankCatalog::class, 'ranks'],
    ];

    private SkillLevelStore $skillLevels;
    private DefinitionStore $store;
    private CatalogRevision $revision;

    public function __construct(private readonly ConnectionInterface $db)
    {
        $this->revision = new CatalogRevision($db);
        $this->store = new DefinitionStore($db, $this->revision);
        $this->skillLevels = new SkillLevelStore($db);
    }

    public function get(string $key): AbstractCatalog
    {
        if (!isset(self::SERVICES[$key])) throw new ApiProblem('unknown_catalog', 'Справочник не найден. Обновите страницу и выберите доступный справочник', 404);
        $class = self::SERVICES[$key][0];
        return $this->makeCatalog($class);
    }

    /** Закрытый перечень также используется переносом; названия таблиц не приходят из JSON. */
    public static function keys(): array { return array_keys(self::SERVICES); }

    public static function tableFor(string $key): string
    {
        if (!isset(self::SERVICES[$key])) throw new ApiProblem('unknown_catalog', 'Справочник не найден. Обновите страницу и выберите доступный справочник', 404);
        return self::SERVICES[$key][1];
    }

    public function list(string $key, User $actor, string $search, ?bool $enabled, int $page, array $filters): array
    {
        Access::admin($actor);
        return $this->revision->read(function () use ($key, $actor, $search, $enabled, $page, $filters): array {
            // Строки и селекторы принадлежат одной согласованной версии.
            $result = $this->get($key)->list($actor, $search, $enabled, $page, $filters);
            $result['lookups'] = $this->lookups();
            return $result;
        });
    }

    public function references(User $actor): array
    {
        Access::admin($actor);
        return $this->revision->read(fn () => $this->lookups());
    }

    /** Рабочая база использует те же каталоги, формы, версии и валидаторы. */
    public function listForWorkspace(string $key, User $actor, string $search, ?bool $enabled, int $page, array $filters): array
    {
        \Maxcuso\Roleplay\Workspace\WorkspaceAccess::catalog($actor, $key, 'view');
        return $this->revision->read(function () use ($key, $actor, $search, $enabled, $page, $filters): array {
            $result = $this->get($key)->list($actor, $search, $enabled, $page, $filters);
            // Только справочные данные редакторов, без персонажей и настроек форума.
            $result['lookups'] = [];
            foreach (array_unique([$key, 'attributes', 'skills', 'proficiencies', 'paths', 'item-types', ...($key === 'items' ? ['spells'] : [])]) as $reference) {
                $result['lookups'][$reference] = $this->definitionsFor($reference);
            }
            $result['capabilities'] = \Maxcuso\Roleplay\Workspace\WorkspaceAccess::capabilities($actor);
            return $result;
        });
    }

    /** Узкий контракт редактора из окна выдачи: без настроек рас, персонажей и правил создания. */
    public function creationForm(string $key, User $actor): array
    {
        $this->assertCreatable($key, $actor);
        return $this->revision->read(function (int $revision) use ($key): array {
            $keys = $key === 'spells' ? ['paths'] : ($key === 'items' ? ['item-types', 'proficiencies', 'attributes', 'skills', 'spells', 'paths'] : ['attributes', 'skills']);
            $lookups = [];
            foreach ($keys as $reference) $lookups[$reference] = $this->definitionsFor($reference);
            return ['revision' => $revision, 'rows' => [], 'total' => 0, 'page' => 1, 'per_page' => 30, 'lookups' => $lookups];
        });
    }

    public function createForModerator(string $key, int $revision, array $fields, User $actor): array
    {
        $this->assertCreatable($key, $actor);
        return $this->get($key)->save(null, $revision, $fields, $actor);
    }

    private function assertCreatable(string $key, User $actor): void
    {
        Access::catalogCreate($actor);
        if (!in_array($key, ['perks', 'spells', 'items'], true)) {
            throw new ApiProblem('catalog_creation_forbidden', 'Создание записей этого справочника из окна выдачи недоступно. Откройте справочник в панели администратора', 403);
        }
    }

    /**
     * Внутреннее пакетное чтение определений для уже загруженного состояния.
     * Вызывается под общей блокировкой справочников, не является публичным API без проверки прав.
     */
    public function definitionsFor(string $key, ?array $ids = null): array
    {
        $catalog = $this->get($key);
        $table = self::SERVICES[$key][1];
        return $catalog->presentMany($this->store->references($table, $ids));
    }

    /** Репозитории дочерних записей подключаются здесь, а не в контроллерах. */
    private function makeCatalog(string $class): AbstractCatalog
    {
        if ($class === SkillCatalog::class) return new SkillCatalog($this->store, $this->skillLevels);
        if ($class === RankCatalog::class) return new RankCatalog($this->store, $this->db);
        return new $class($this->store);
    }

    private function lookups(): array
    {
        $result = [];
        foreach (self::SERVICES as $key => [$class, $table]) {
            $catalog = $this->makeCatalog($class);
            $result[$key] = $catalog->presentMany($this->store->references($table));
        }
        return $result;
    }
}
