<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Character\Application;

use Maxcuso\Roleplay\Catalog\Shared\FieldValues;

/** Шаблон — обычный текст для редактора форума, не исполняемый PHP. */
final class ApplicationConfiguration
{
    public static function defaults(): array
    {
        return [
            'tag_id' => null,
            'title_template' => 'Анкета: {name}',
            'body_template' => trim(file_get_contents(__DIR__.'/../../../resources/presentation/application-template.txt')),
        ];
    }

    public function normalize(mixed $input): array
    {
        if (!is_array($input)) FieldValues::invalid('Нужен объект настроек темы анкеты.');
        FieldValues::allowed($input, ['tag_id', 'title_template', 'body_template']);
        $input += self::defaults();
        return [
            'tag_id' => FieldValues::integer($input['tag_id'], 'Тег анкет', 1, 4294967295, true),
            'title_template' => FieldValues::text($input['title_template'], 'Шаблон заголовка', 200, true),
            'body_template' => FieldValues::text($input['body_template'], 'Шаблон литературной анкеты', 60000, true),
        ];
    }

    public function render(array $configuration, array $character, string $race): array
    {
        $name = trim($character['first_name'].' '.($character['last_name'] ?? ''));
        $values = ['{name}' => $name, '{age}' => (string) $character['age'], '{race}' => $race];
        $title = mb_substr(strtr($configuration['title_template'], $values), 0, 80);
        $body = strtr($configuration['body_template'], $values);
        if (mb_strlen($title) < 3 || mb_strlen($body) > 63000) {
            FieldValues::invalid('Проверь длину заголовка и шаблона после подстановки имени.');
        }
        return ['title' => $title, 'content' => $body];
    }
}