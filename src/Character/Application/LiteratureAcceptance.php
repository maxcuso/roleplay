<?php
declare(strict_types=1);
namespace Maxcuso\Roleplay\Character\Application;

/** Принято — самостоятельное сообщение, а не цитата или упоминание плашки внутри текста. */
final class LiteratureAcceptance
{
    public static function matches(string $content): bool
    {
        if (!preg_match('~\A\s*\[success\b([^\]]*)\]\s*\[/success\]\s*\z~iu', $content, $match)) return false;
        preg_match_all('~([a-z]+)\s*=\s*(?:"([^"]*)"|\'([^\']*)\'|([^\s]+))~iu', $match[1], $attributes, PREG_SET_ORDER);
        $values = [];
        foreach ($attributes as $attribute) {
            $key = strtolower($attribute[1]);
            if (isset($values[$key])) return false;
            $values[$key] = ($attribute[2] ?? '') !== '' ? $attribute[2] : (($attribute[3] ?? '') !== '' ? $attribute[3] : $attribute[4] ?? '');
        }
        return ($values['title'] ?? '') === 'Принято'
            && strcasecmp($values['font'] ?? '', '#1E2019') === 0
            && strcasecmp($values['bg'] ?? '', '#A9C7AA') === 0
            && strcasecmp($values['border'] ?? '', '#A9C7AA') === 0;
    }
}