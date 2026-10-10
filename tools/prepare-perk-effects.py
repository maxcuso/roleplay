"""Обновляет подготовленный JSON перков, не импортируя его в базу.

В этом файле только данные правил. Расчёт не зависит от ключей и названий перков.
После ручного изменения правил поправь нужную запись ниже и запусти файл снова.
"""
from pathlib import Path
import json
import shutil

WORKSPACE = Path(__file__).resolve().parents[3]
TARGET = WORKSPACE / "jsons" / "perks-new.json"


def effect(mechanic, value, **settings):
    """Полный переносимый формат: ссылки — ключи справочников, не ID локальной базы."""
    return {
        "name": None, "description": None, "icon": None,
        "mechanic": mechanic, "operation": "add",
        "attribute_key": None, "skill_key": None, "stat": None,
        "value": value, "condition": "always", "condition_text": None,
        "scope": "all", "stack_group": None, **settings,
    }


UNARMED = ["tak-zakalyalas-stal", "kogti", "klyki", "khvost"]
NUMERIC = {
    key: [effect("stat", 50, stat="melee_damage", operation="base_percent",
        condition="unarmed", stack_group="unarmed-base-damage")]
    for key in UNARMED
}
NUMERIC.update({
    "besshumnyy-ubiytsa": [effect("stat", 1, stat="all_attacks", operation="tiers",
        condition="manual", condition_text="Атака из укрытия, пока персонаж не замечен.")],
    "kamennoe-serdtse": [effect("incoming_damage", -1, operation="tiers",
        condition="manual", condition_text="Урон от отравления.")],
    "svirepaya-stoykost": [effect("incoming_damage", -1, operation="tiers",
        condition="manual", condition_text="При получении Серьёзного урона, один раз за арку.")],
    "infernalnoe-nasledie": [effect("incoming_damage", -1, operation="tiers",
        condition="manual", condition_text="Урон от огня.")],
    # Удваивается +1 за уровень навыка, а не другие прибавки к проверке.
    "ustrashayushchaya-vneshnost": [effect("skill_check", 100, skill_key="diplomacy",
        operation="base_percent", condition="manual",
        condition_text="Проверка Дипломатии при запугивании.")],
    "zverinyy-nyukh": [effect("skill_check", 100, skill_key="awareness",
        operation="base_percent", condition="manual",
        condition_text="Проверка Внимательности при обонянии или слежке по запаху.")],
})
# Это бонус получившему угощение, не постоянная прибавка владельцу перка.
TREAT_CONDITION = "Следующая проверка получившего угощение существа; раз в день."
NUMERIC["malenkie-radosti"] = [
    effect("attribute_check", 3, attribute_key="charisma", condition="manual",
        condition_text=TREAT_CONDITION),
    *[effect("skill_check", 3, skill_key=key, condition="manual", condition_text=TREAT_CONDITION)
      for key in ["diplomacy", "deception", "insight"]],
]
DESCRIPTIONS = {
    "tak-zakalyalas-stal": "Атаки в безоружном бою дополнительно наносят половину базового урона.",
    "kogti": "Безоружные атаки дополнительно наносят половину базового урона.",
    "klyki": "Укус в безоружном бою дополнительно наносит половину базового урона.",
    "khvost": "Атаки хвостом в безоружном бою дополнительно наносят половину базового урона.",
}


def main():
    document = json.loads(TARGET.read_text(encoding="utf-8"))
    rows = document["catalogs"]["perks"]
    assert len(rows) == 52 and len({row["key"] for row in rows}) == 52
    assert not set(NUMERIC) - {row["key"] for row in rows}, "Не найдены перки для бонусов"
    backup = TARGET.parent / "backups" / "perks-new-before-numeric-effects-2026-10-09.json"
    backup.parent.mkdir(exist_ok=True)
    if not backup.exists():
        shutil.copyfile(TARGET, backup)
    for row in rows:
        key = row["key"]
        # Остальные способности остаются в описании, без текстовых эффектов и придуманных чисел.
        row["effects"] = NUMERIC.get(key, [])
        if key in DESCRIPTIONS:
            row["description"] = DESCRIPTIONS[key] + " Этот бонус не складывается с другими такими бонусами."
    TARGET.write_text(json.dumps(document, ensure_ascii=False, indent=2) + "\n", encoding="utf-8")
    print(f"Updated {len(rows)} perks; {len(NUMERIC)} with {sum(map(len, NUMERIC.values()))} numeric effects.")


if __name__ == "__main__":
    main()
