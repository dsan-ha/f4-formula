<?php

namespace App\Utils\Log;

use App\F4;

class LogRotator
{
    protected const LOG_FOLDER = 'lib/tmp/logs/';
    protected const FILE_SIZE = 1048576;
    protected const ROTATED_FILES = 5;
    protected const COMPRESS_OLD = false;

    public static function rotateFile(string $file = null): void
    {
        $f4 = F4::instance();
        $maxFileSizeBytes = $f4->g('log_rotate.max_file_size_bytes',self::FILE_SIZE);
        $last = $f4->g('log_rotate.max_rotated_files',self::ROTATED_FILES);
        $compressOld = $f4->g('log_rotate.compress_old',self::COMPRESS_OLD);


        if ( !file_exists($file) || filesize($file) < $maxFileSizeBytes) {
            return;
        }

        $oldest = "{$file}.{$last}" . ($compressOld ? '.gz' : '');
        if (file_exists($oldest)) {
            unlink($oldest);
        }

        for ($i = $last - 1; $i >= 1; $i--) {
            $src = "{$file}.{$i}" . ($compressOld ? '.gz' : '');
            $dst = "{$file}." . ($i + 1) . ($compressOld ? '.gz' : '');
            if (file_exists($src)) {
                rename($src, $dst);
            }
        }

        $rotated = "{$file}.1";
        rename($file, $rotated);

        if ($compressOld) {
            $gz = gzopen($rotated . '.gz', 'wb9');
            gzwrite($gz, file_get_contents($rotated));
            gzclose($gz);
            unlink($rotated);
        }
    }

    public static function rotateDirectory(string $dir = null): void
    {
        $f4 = F4::instance();
        if(empty($dir))
            $dir = SITE_ROOT . $f4->g('log.log_folder',self::LOG_FOLDER);
        foreach (glob($dir . '/*.log') as $file) {
            self::rotateFile($file);
        }
    }
}
