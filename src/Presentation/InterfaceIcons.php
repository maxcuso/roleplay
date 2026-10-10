<?php

declare(strict_types=1);

namespace Maxcuso\Roleplay\Presentation;

/**
 * PHP и интерфейс используют resources/presentation/icons.json.
 * Здесь только служебные значки. Иконки сущностей читаются из их справочников.
 */
final class InterfaceIcons
{
    private ?array $icons = null;
    private ?array $replacements = null;

    public function get(string $name): string
    {
        $icons = $this->definitions();
        $value = $icons[$name] ?? null;
        if (!is_string($value) || trim($value) === '') {
            // Отсутствующий служебный ключ — ошибка кода, а не данных пользователя.
            throw new \InvalidArgumentException('Не задана служебная иконка: '.$name);
        }
        return $this->resolve($value);
    }

    public function catalogue(?string $value): string
    {
        $original = trim($value ?? '');
        return $original !== '' ? $this->resolve($original) : $this->get('fallback');
    }

    /** Подмена только для отображения; сохранение справочника использует исходные классы. */
    public function resolve(string $value): string
    {
        $original = trim($value);
        $replacements = $this->localReplacements();
        return $replacements[$original] ?? $original;
    }

    private function localReplacements(): array
    {
        if ($this->replacements !== null) return $this->replacements;

        $path = __DIR__.'/../../resources/presentation/icons.local.json';
        if (!is_file($path)) return $this->replacements = [];

        $json = file_get_contents($path);
        if ($json === false) throw new \RuntimeException('Не удалось прочитать локальные замены иконок.');
        $config = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
        if (!is_array($config) || !is_bool($config['enabled'] ?? null)) {
            throw new \RuntimeException('В icons.local.json нужно явно задать enabled: true или false.');
        }
        if (!$config['enabled']) return $this->replacements = [];

        $replacements = $config['replacements'] ?? null;
        if (!is_array($replacements)) {
            throw new \RuntimeException('В icons.local.json replacements должен быть объектом замен.');
        }
        foreach ($replacements as $original => $replacement) {
            $pattern = '/^[a-zA-Z0-9_-]+(?: +[a-zA-Z0-9_-]+)*$/D';
            if (!is_string($original) || !preg_match($pattern, $original)
                || !is_string($replacement) || !preg_match($pattern, $replacement)) {
                throw new \RuntimeException('Неверные классы в локальной замене иконки.');
            }
        }
        // Настройки перечитываются новым экземпляром в следующем запросе PHP.
        return $this->replacements = $replacements;
    }

    private function definitions(): array
    {
        if ($this->icons === null) {
            $path = __DIR__.'/../../resources/presentation/icons.json';
            $json = file_get_contents($path);
            if ($json === false) throw new \RuntimeException('Не удалось прочитать файл служебных иконок.');
            $icons = json_decode($json, true, flags: JSON_THROW_ON_ERROR);
            if (!is_array($icons)) throw new \RuntimeException('Файл иконок должен содержать объект настроек.');
            $this->icons = $icons;
        }
        return $this->icons;
    }
}
