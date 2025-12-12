<?php

namespace App\Utils;

final class Assets
{
    private $cssFiles = [];
    private $jsFiles = [];
    protected const SHOW_ERROR = false;

    // Предотвращаем клонирование
    private function __clone()
    {
    }

    // Предотвращаем десериализацию
    public function __wakeup()
    {
        throw new \Exception("Cannot unserialize singleton");
    }

    protected function alert(string $msg): void
    {
        app()->addAlert($msg);
    }

    /** Определить, локальный ли путь (а не http/https/data:) */
    protected function isLocalPath(string $path): bool
    {
        return !preg_match('~^(?:https?:)?//|^data:~i', $path);
    }

    /**
     * Добавляет CSS файл в коллекцию
     * @param string $path - путь к файлу
     * @param int $priority - приоритет (чем меньше, тем раньше будет в объединенном файле)
     */
    public function addCss(string $path, array $params = []): self
    {
        $def = [
            'priority' => 10
        ];
        $params = array_merge($def, $params);
        
        if (!$this->ensureExists($path)) {
            $this->alert('CSS не найден: ' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            // просто не добавляем файл, едем дальше
            return $this;
        } 
        $params['data_update'] = $this->get_time_update($path);
        $this->cssFiles[$path] = $params;
        return $this;
    }

    /** Преобразовать web-путь в файловый (если надо) и проверить существование */
    protected function ensureExists(string $path): bool
    {
        if (!$this->isLocalPath($path)) {
            return true; // внешние URL не проверяем файлово
        }

        // Попробуем несколько вариантов: абсолют и относительный к DOCROOT
        $fs = $path;
        if (!is_file($fs)) {
            $fs2 = rtrim(SITE_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
            if (is_file($fs2)) return true;
            
            return false;
        }
        return true;
    }

    /**
     * Добавляет JS файл в коллекцию
     * @param string $path - путь к файлу
     * @param int $priority - приоритет (чем меньше, тем раньше будет в объединенном файле)
     * @param bool $inFooter - подключать в подвале страницы
     */
    public function addJs(string $path, array $params = []): self
    {
        $def = [
            'priority' => 10,
            'inFooter' => false
        ];
        $params = array_merge($def, $params);
        if (!$this->ensureExists($path)) {
            $this->alert('JS не найден: ' . htmlspecialchars($path, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'));
            return $this;
        }
        $params['data_update'] = $this->get_time_update($path);

        $this->jsFiles[$path] = $params;
        return $this;
    }

    /**
     * Объединяет и минифицирует CSS файлы
     * @return string - минифицированный CSS код
     */
    public function processCss(): string
    {
        if (empty($this->cssFiles)) {
            return '';
        }

        // Сортируем файлы по приоритету
        asort($this->cssFiles);

        $combinedCss = '';

        foreach (array_keys($this->cssFiles) as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $combinedCss .= $this->minifyCss($content);
            }
        }

        return $combinedCss;
    }

    /**
     * Объединяет и минифицирует JS файлы
     * @param bool $inFooter - обрабатывать файлы для подвала
     * @return string - минифицированный JS код
     */
    public function processJs(bool $inFooter = false): string
    {
        $filteredFiles = array_filter($this->jsFiles, function ($item) use ($inFooter) {
            return $item['in_footer'] === $inFooter;
        });

        if (empty($filteredFiles)) {
            return '';
        }

        // Сортируем файлы по приоритету
        uasort($filteredFiles, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        $combinedJs = '';

        foreach (array_keys($filteredFiles) as $file) {
            if (file_exists($file)) {
                $content = file_get_contents($file);
                $combinedJs .= $this->minifyJs($content);
            }
        }

        return $combinedJs;
    }

    /**
     * Минифицирует CSS код
     * @param string $css - исходный CSS
     * @return string - минифицированный CSS
     */
    private function minifyCss(string $css): string
    {
        // Удаляем комментарии
        $css = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $css);

        // Удаляем пробелы, табы, переносы строк
        $css = str_replace(["\r\n", "\r", "\n", "\t", '  ', '    ', '    '], '', $css);

        // Удаляем ненужные пробелы
        $css = preg_replace('/\s*([{}|:;,])\s*/', '$1', $css);
        $css = preg_replace('/\s\s+(.*)/', '$1', $css);

        return trim($css);
    }

    /**
     * Минифицирует JS код (базовая реализация)
     * @param string $js - исходный JS
     * @return string - минифицированный JS
     */
    private function minifyJs(string $js): string
    {
        // Удаляем однострочные комментарии
        $js = preg_replace('/(\/\/.*$)/m', '', $js);

        // Удаляем многострочные комментарии
        $js = preg_replace('!/\*[^*]*\*+([^/][^*]*\*+)*/!', '', $js);

        // Удаляем лишние пробелы и переносы строк
        $js = preg_replace('/\s+/', ' ', $js);

        return trim($js);
    }

    /**
     * Сохраняет объединенный CSS в файл
     * @param string $outputPath - путь для сохранения
     * @return bool - успех операции
     */
    public function saveCombinedCss(string $outputPath): bool
    {
        $css = $this->processCss();
        return file_put_contents($outputPath, $css) !== false;
    }

    /**
     * Сохраняет объединенный JS в файл
     * @param string $outputPath - путь для сохранения
     * @param bool $inFooter - для подвала страницы
     * @return bool - успех операции
     */
    public function saveCombinedJs(string $outputPath, bool $inFooter = false): bool
    {
        $js = $this->processJs($inFooter);
        return file_put_contents($outputPath, $js) !== false;
    }

    public function renderCss()
    {
        if (empty($this->cssFiles)) {
            return '';
        }

        // Сортируем файлы по приоритету
        uasort($this->cssFiles, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });
        $html = '';
        foreach ($this->cssFiles as $path => $p) {

            $url = $path;
            if (!empty($p['data_update'])) {
                $url .= '?v=' . $p['data_update'];
            }
            $html .= '<link rel="stylesheet" href="'.$url.'" type="text/css" />';

        }
        return $html;
    }

    public function renderJs()
    {
        if (empty($this->jsFiles)) {
            return '';
        }

        // Сортируем файлы по приоритету
        uasort($this->jsFiles, function ($a, $b) {
            return $a['priority'] <=> $b['priority'];
        });

        $combinedCss = '';
        $html = '';

        foreach ($this->jsFiles as $path => $p) {
            $url = $path;
            if (!empty($p['data_update'])) {
                $url .= '?v=' . $p['data_update'];
            }
            $html .= '<script src="' . $url . '"></script>';

        }
        return $html;
    }

    public function get_time_update($path){
        $time = 0;
        if (!$this->isLocalPath($path)) return $time; 
        $file = rtrim(SITE_ROOT, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . ltrim($path, '/\\');
        if(is_file($file)) $time = filemtime($file);
        return $time;
    }
}
