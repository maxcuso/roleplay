<?php

namespace Maxcuso\Roleplay\Events\Formatter;

use s9e\TextFormatter\Configurator;
use s9e\TextFormatter\Parser;
use s9e\TextFormatter\Parser\Tag;

class ConfigureEventBbcode
{
    public function __invoke(Configurator $config): void
    {
        $config->tags->add('QUEST_DESCRIPTION')->template = '<xsl:apply-templates/>';
        foreach (['B' => 'strong', 'I' => 'em', 'S' => 's', 'U' => 'u'] as $name => $htmlTag) {
            $config->tags->add('QUEST_DESCRIPTION_' . $name)->template = '<' . $htmlTag . '><xsl:apply-templates/></' . $htmlTag . '>';
        }
        $config->BBCodes->addCustom(
            '[quest={UINT;optional} title={TEXT;optional} min={TEXT;optional} max={TEXT;optional} delay={TEXT;optional} leader={TEXT;optional} start={TEXT;optional} draft={TEXT;optional} type={TEXT;optional} difficulty={TEXT;optional} color={TEXT;optional} xp={TEXT;optional} gold={TEXT;optional} items={TEXT;optional}]{TEXT}[/quest]',
            '<div class="QuestEventEmbed" data-quest-event-id="{@quest}">
                <xsl:choose>
                    <xsl:when test="@available = 1">
                        <div class="QuestEventEmbed-title"><xsl:value-of select="@title"/></div>
                        <div class="QuestEventEmbed-description"><xsl:apply-templates select="QUEST_DESCRIPTION"/></div>
                        <xsl:if test="@reward_experience &gt; 0 or @reward_gold &gt; 0 or @reward_items &gt; 0">
                            <div class="QuestEventEmbed-rewards">
                                <xsl:if test="@reward_experience &gt; 0"><span title="Опыт: {@reward_experience}"><i class="fas fa-star" aria-hidden="true"/> <xsl:value-of select="@reward_experience"/> XP</span></xsl:if>
                                <xsl:if test="@reward_gold &gt; 0"><span title="Золото: {@reward_gold}"><i class="fas fa-coins" aria-hidden="true"/> <xsl:value-of select="@reward_gold"/></span></xsl:if>
                                <xsl:if test="@reward_items &gt; 0"><span title="Предметы: {@reward_items}" role="img" aria-label="Предметы: {@reward_items}"><svg class="QuestReward-chest" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round" aria-hidden="true"><path d="M3 11V8a5 5 0 0 1 5-5h8a5 5 0 0 1 5 5v3M3 11h18v9a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1zM8 3v8m8-8v8M3 14h7m4 0h7"/><rect x="10" y="11" width="4" height="6" rx="1"/></svg> <xsl:value-of select="@reward_items"/></span></xsl:if>
                            </div>
                        </xsl:if>
                        <div>Количество участников: <xsl:value-of select="@minimum"/>–<xsl:value-of select="@maximum"/></div>
                        <div>Записались: <xsl:value-of select="@count"/></div>
                        <div>Ведущий: <xsl:value-of select="@leader"/></div>
                    </xsl:when>
                    <xsl:otherwise>
                        <div class="QuestEventEmbed-title">Событие № <xsl:value-of select="@quest"/></div>
                        <div>Данные события недоступны в этом просмотре.</div>
                        <xsl:if test="not(@quest)">
                            <div class="QuestEventEmbed-description"><xsl:apply-templates/></div>
                        </xsl:if>
                    </xsl:otherwise>
                </xsl:choose>
            </div>',
            ['tagName' => 'QUEST_EVENT']
        );

        // A short reference has no closing tag; full creation codes still use [/quest].
        $config->tags['QUEST_EVENT']->rules->autoClose();
        // Preserve the raw description; the separate allowlist parser renders its basic BBCode.
        $config->tags['QUEST_EVENT']->rules->ignoreTags();
        $config->tags['QUEST_EVENT']->filterChain
            ->append([self::class, 'keepFullCodeOpen'])
            ->setJS('function(tag) { if (!tag.hasAttribute("quest")) tag.removeFlags(1); return true; }');
    }

    public static function keepFullCodeOpen(Tag $tag): bool
    {
        if (!$tag->hasAttribute('quest')) {
            $tag->removeFlags(Parser::RULE_AUTO_CLOSE);
        }
        return true;
    }
}
