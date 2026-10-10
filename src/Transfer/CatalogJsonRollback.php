<?php
declare(strict_types=1);

namespace Maxcuso\Roleplay\Transfer;

/** Внутренний выход из пробного импорта. Откатывает транзакцию, возвращая готовый отчёт. */
final class CatalogJsonRollback extends \RuntimeException
{
    public function __construct(public readonly array $report) { parent::__construct('Catalog JSON rollback'); }
}
