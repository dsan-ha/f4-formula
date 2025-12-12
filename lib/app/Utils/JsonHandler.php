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
     * Декодирует JSON из php://input с защитой и логированием (для Telegram)
     * @param bool $assoc
     * @param int $depth
     * @param int $options
     * @return mixed
     * @throws JsonException
     */
    public static function decodeFromInput(bool $assoc = true, int $depth = 512, int $options = 0)
    {
        $raw = file_get_contents('php://input');

        try {
            return self::decode($raw, $assoc, $depth, $options);
        } catch (JsonException $e) {
            $f4 = F4::instance();
            $log = $f4->get('log.json_on');
            if($log)
                self::logSuspiciousPayload($raw, $e->getMessage());
            throw $e;
        }
    }

    /**
     * Декодирует JSON-строку с обработкой ошибок и проверками безопасности
     * -
     * @param string $json JSON-строка
     * @param bool $assoc Возвращать ассоциативный массив (true) или объект (false)
     * @param int $depth Максимальная глубина вложенности
     * @param int $options Опции декодирования
     * @return mixed Декодированные данные
     * @throws JsonException Если декодирование не удалось или обнаружены проблемы безопасности
     */
    public static function decode(string $json, bool $assoc = true, int $depth = 512, int $options = 0)
    {
        // Проверка на пустую строку
        if (empty($json)) {
            throw new JsonException('Empty JSON string');
        }

        // Проверка глубины вложенности
        if ($depth < 1) {
            throw new JsonException('Depth must be greater than zero');
        }

        // Проверка на потенциально опасные конструкции перед декодированием
        if (self::containsMaliciousContent($json)) {
            throw new JsonException('Potentially dangerous JSON content detected');
        }

        // Декодирование с обработкой ошибок
        $data = json_decode($json, $assoc, $depth, $options | JSON_THROW_ON_ERROR | JSON_OBJECT_AS_ARRAY);

        // Дополнительная проверка после декодирования
        if (self::containsMaliciousStructures($data)) {
            throw new JsonException('Potentially dangerous data structures detected');
        }

        return $data;
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

    /**
     * Проверяет, является ли строка валидным JSON
     * -
     * @param string $json Проверяемая строка
     * @return bool Валиден ли JSON
     */
    public static function isValid(string $json): bool
    {
        try {
            self::decode($json);
            return true;
        } catch (JsonException $e) {
            return false;
        }
    }

    /**
     * Логирует подозрительный JSON-запрос
     * @param string $raw
     * @param string $reason
     * @return void
     */
    private static function logSuspiciousPayload(string $raw, string $reason): void
    {
        $f4 = F4::instance();
        $logFile = $f4->get('log.json_log');
        if (!$logFile) return;
        $logFile = SITE_ROOT . $logFile;
        $txt = sprintf(
            "Suspicious JSON from IP %s: %s\nReason: %s\n\n",
            $_SERVER['REMOTE_ADDR'] ?? 'unknown',
            substr($raw, 0, 1000),
            $reason
        );
        Log::writeIn($txt, LogLevel::ERROR);
    }
}
