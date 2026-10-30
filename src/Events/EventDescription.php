<?php
namespace Maxcuso\Roleplay\Events;

/** A small allowlist parser: text is always escaped, only four paired tags become markup. */
class EventDescription
{
    public static function html(?string $source): string
    {
        return self::format($source ?? '', ['b' => 'strong', 'i' => 'em', 's' => 's', 'u' => 'u']);
    }

    public static function xml(?string $source): string
    {
        return self::format($source ?? '', ['b' => 'QUEST_DESCRIPTION_B', 'i' => 'QUEST_DESCRIPTION_I', 's' => 'QUEST_DESCRIPTION_S', 'u' => 'QUEST_DESCRIPTION_U']);
    }

    private static function format(string $source, array $tags): string
    {
        $stack = [['tag' => '', 'opening' => '', 'content' => '']];
        foreach (preg_split('/(\[\/?[bisu]\])/i', $source, -1, PREG_SPLIT_DELIM_CAPTURE) as $part) {
            if (preg_match('/^\[([bisu])\]$/iD', $part, $match)) {
                $stack[] = ['tag' => strtolower($match[1]), 'opening' => $part, 'content' => ''];
            } elseif (preg_match('/^\[\/([bisu])\]$/iD', $part, $match) && count($stack) > 1 && end($stack)['tag'] === strtolower($match[1])) {
                $frame = array_pop($stack);
                $tag = $tags[$frame['tag']];
                $stack[count($stack) - 1]['content'] .= '<' . $tag . '>' . $frame['content'] . '</' . $tag . '>';
            } else {
                $stack[count($stack) - 1]['content'] .= htmlspecialchars($part, ENT_QUOTES | ENT_SUBSTITUTE | ENT_XML1, 'UTF-8');
            }
        }
        // Incomplete opening tags remain visible, rather than formatting the rest of the card.
        while (count($stack) > 1) {
            $frame = array_pop($stack);
            $stack[count($stack) - 1]['content'] .= $frame['opening'] . $frame['content'];
        }
        return $stack[0]['content'];
    }
}
