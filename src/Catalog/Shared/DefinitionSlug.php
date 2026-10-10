<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Catalog\Shared;

/** Транслитерация из прежнего расширения. Отображаемое имя и системный ключ различаются. */
final class DefinitionSlug
{
    public static function fromName(string $name, string $prefix = 'entry'): string
    {
        $letters = [
            'а' => 'a', 'б' => 'b', 'в' => 'v', 'г' => 'g', 'д' => 'd',
            'е' => 'e', 'ё' => 'yo', 'ж' => 'zh', 'з' => 'z', 'и' => 'i',
            'й' => 'y', 'к' => 'k', 'л' => 'l', 'м' => 'm', 'н' => 'n',
            'о' => 'o', 'п' => 'p', 'р' => 'r', 'с' => 's', 'т' => 't',
            'у' => 'u', 'ф' => 'f', 'х' => 'kh', 'ц' => 'ts', 'ч' => 'ch',
            'ш' => 'sh', 'щ' => 'shch', 'ъ' => '', 'ы' => 'y', 'ь' => '',
            'э' => 'e', 'ю' => 'yu', 'я' => 'ya',
        ];
        $transliterated = strtr(mb_strtolower($name), $letters);
        $slug = trim(preg_replace('/[^a-z0-9]+/', '-', $transliterated), '-');
        if ($slug === '' || !ctype_alpha($slug[0])) {
            $slug = $prefix.'-'.$slug;
        }
        // Оставляем место для суффикса, если одинаковое имя уже существует.
        return rtrim(substr($slug, 0, 90), '-');
    }
}