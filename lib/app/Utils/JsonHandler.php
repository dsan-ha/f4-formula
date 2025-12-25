<?php

namespace App\Utils;

use App\F4;
use App\Utils\Log;
use App\Utils\Log\LogLevel;

class JsonHandler
{
    protected static bool $logSuspicious = true;

    /**
     * Кодирует данные в JSON с обработкой ошибок и дополнительными проверками
     * -
     * @param mixed $data Данные для кодирования
     * @param int $options Опции кодирования (по умолчанию JSON_UNESCAPED_UNICODE)
     * @param int $depth Максимальная глубина вложенности
     * @return string JSON-строка
     * @throws JsonException Если кодирование не удалось
     */
    public static function encode($data, int $options = JSON_UNESCAPED_UNICODE, int $depth = 512): string
    {
        // Проверка на циклические ссылки
        if (self::hasCircularReference($data)) {
            throw new JsonException('Circular reference detected in data');
        }

        // Проверка глубины вложенности
        if ($depth < 1) {
            throw new JsonException('Depth must be greater than zero');
        }

        // Кодирование с обработкой ошибок
        $json = json_encode($data, $options | JSON_THROW_ON_ERROR, $depth);

        // Дополнительная проверка на потенциально опасные конструкции
        if (self::containsMaliciousContent($json)) {
            throw new JsonException('Potentially dangerous content detected');
        }

        return $json;
    }

    /**
     * Проверяет данные на наличие циклических ссылок
     * -
     * @param mixed $data Проверяемые данные
     * @param array $seen Внутренний параметр для рекурсии
     * @return bool Есть ли циклические ссылки
     */
    private static function hasCircularReference($data, array &$seen = []): bool
    {
        if (!is_array($data) && !is_object($data)) {
            return false;
        }

        foreach ($seen as $s) {
            if ($s === $data) return true;
        }

        $seen[] = $data;

        foreach ((array)$data as $value) {
            if (is_array($value) || is_object($value)) {
                if (self::hasCircularReference($value, $seen)) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Проверяет JSON-строку на потенциально опасные конструкции
     * -
     * @param string $json JSON-строка
     * @return bool Есть ли опасные конструкции
     */
    private static function containsMaliciousContent(string $json): bool
    {
        // Проверка на потенциальные JSON-инъекции
        if (preg_match('/"\s*:\s*["\']\s*\+?\s*[a-z0-9_]+\s*\(\s*["\']/i', $json)) {
            return true;
        }

        // Проверка на подозрительные escape-последовательности
        if (preg_match('/\\\\[^u"]/', $json)) {
            return true;
        }

        // Проверка на слишком длинные строки (возможная попытка переполнения)
        if (preg_match('/"[^"]{10000,}"/', $json)) {
            return true;
        }

        return false;
    }

    /**
     * Проверяет декодированные данные на опасные структуры
     * -
     * @param mixed $data Проверяемые данные
     * @return bool Есть ли опасные структуры
     */
    private static function containsMaliciousStructures($data): bool
    {
        if (is_array($data) || is_object($data)) {
            foreach ($data as $key => $value) {
                // Проверка ключей на потенциально опасные имена
                if (is_string($key) && preg_match('/^(on|javascript|vbscript|data):/i', $key)) {
                    return true;
                }

                /*if (is_object($value) && get_class($value) !== 'stdClass') {
                    throw new JsonException('Unexpected object type detected');
                }*/

                // Рекурсивная проверка значений
                if (self::containsMaliciousStructures($value)) {
                    return true;
                }
            }
        }

        // Проверка строк на потенциально опасное содержимое
        if (is_string($data) && (preg_match('/<(script|iframe|frame|object|embed)/i', $data) || preg_match('/(on\w+=|javascript:|data:text\/html|<script\b[^>]*>)/i', $data)) ) {
            return true;
        }

        return false;
    }
}
