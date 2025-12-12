<?php

namespace App\Utils;

use App\F4;
use App\Utils\Cache\ApcCacheAdapter;
use App\Utils\Cache\FileCacheAdapter;
use App\Utils\Cache\CacheInterface;
use App\Utils\Security\IpBanManager;
use App\Utils\Log;
use App\Utils\Log\LogLevel;

class Firewall
{
    /** Подозрительные User-Agent'ы */
    protected static array $badUserAgents = [
        'curl', 'wget', 'python', 'bot'
    ];

    protected CacheInterface $cache;
    protected string $ip;

    /** Максимальная длина тела запроса */
    protected const MAX_BODY_LENGTH = 65536;

    /** Ключи кэша */
    const PHPIDS_ACCESS_KEY = 'phpids_access_key';
    const PHPIDS_SIGNATURE_KEY = 'phpids_signature_key';
    const CACHE_FOLDER = 'firewall';

    /** Проверка сигнатур телеграмма */
    protected const RATE_LIMIT = 30;
    protected const RATE_INTERVAL = 60; // секунд
    protected const TELEGRAM_TOKEN_SECRET = ''; // секунд

    /** Порог для DoS-атаки */
    protected const SIGNATURE_INTERVAL = 600; // секунд
    protected const SIGNATURE_FAIL_LIMIT = 3; 
    protected const DOS_MAX_HITS = 10;
    protected const DOS_INTERVAL = 3; // секунд

    public function __construct()
    {
        $f4 = F4::instance();
        //$adapter = new ApcCacheAdapter(); //Не включен
        $adapter = new FileCacheAdapter(); // Лучше не использовать медленный и опасный при больших нагрузках
        $this->cache = new \App\Utils\Cache($adapter);
        $this->ip = self::getIpAddress();
    }

    /** Основной метод проверки */
    public function check(): void
    {
        $ua = strtolower($_SERVER['HTTP_USER_AGENT'] ?? '');
        $length = (int) ($_SERVER['CONTENT_LENGTH'] ?? 0);
        $suffix = date('H');
        $accessList = $this->cache->get(self::PHPIDS_ACCESS_KEY.$suffix, self::CACHE_FOLDER, []);

        $this->updateAccessList($accessList);

        // Проверка по таблице забаненных IP
        $banManager = IpBanManager::instance();
        if ($banManager->isBanned($this->ip)) {
            $this->block("Blocked IP (database): " . $this->ip);
        }

        // Подозрительный User-Agent
        foreach (self::$badUserAgents as $pattern) {
            if (str_contains($ua, $pattern)) {
                self::block("Blocked UA: $ua");
            }
        }

        // Огромный размер тела запроса
        if ($length > self::MAX_BODY_LENGTH) {
            $this->block("Request body too large: $length bytes");
        }

        // Rate limit
        if (!self::checkRateLimit($accessList)) {
            $this->block("Rate limit exceeded for IP: ". $this->ip);
        }

        // DoS detection 
        if (self::checkDos($accessList)) {
            $this->block("Potential DoS attack detected for IP: " . $this->ip);
        }
    }

    /** Проверка сигнатуры Telegram */
    public function checkTelegramSignature(): bool
    {
        $f4 = F3::instance();
        $telegram_secret = $f4->g('firewall.telegram_secret',self::TELEGRAM_TOKEN_SECRET);
        $signature_interval = $f4->g('firewall.signature_interval',self::SIGNATURE_INTERVAL);
        $signature_fail_limit = $f4->g('firewall.signature_fail_limit',self::SIGNATURE_FAIL_LIMIT);
        $header = $_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '';

        if(empty($header) || $header !== $telegram_secret){
            $curTime = time();
            $suffix = date('H');
            $signatureList = $this->cache->get(self::PHPIDS_SIGNATURE_KEY.$suffix, self::CACHE_FOLDER, []);
            $totalInPastRange = 0;

            if(isset($signatureList[$this->ip]) && isset($signatureList[$this->ip][$curTime])){
                $signatureList[$this->ip][$curTime]++; 
            } else {
                $signatureList[$this->ip][$curTime] = 1; 
            }

            $this->cache->set(self::PHPIDS_SIGNATURE_KEY.$suffix, self::CACHE_FOLDER, $signatureList);

            foreach ($signatureList[$this->ip] as $timestamp => $count) {
                if ($curTime - $timestamp < $signature_interval || $curTime == $timestamp) {
                    $totalInPastRange += $count;
                }
            }


            if ($totalInPastRange >= $signature_fail_limit) {
                this->block("Invalid Telegram signature: " . $this->ip);
            }
            
        }
        
    }

    /** Проверка ограничения по количеству запросов */
    protected function checkRateLimit(array $accessList = []): bool
    {
        $curTime = time();
        $totalInPastRange = 0;
        $f4 = F3::instance();
        $rate_limit = $f4->g('firewall.rate_limit',self::RATE_LIMIT);
        $rate_interval = $f4->g('firewall.rate_interval',self::RATE_INTERVAL);
        
        if (isset($accessList[$this->ip])) {
            foreach ($accessList[$this->ip] as $timestamp => $count) {
                if ($curTime - $timestamp < $rate_interval || $curTime == $timestamp) {
                    $totalInPastRange += $count;
                }
            }
        }

        return $totalInPastRange <= $rate_limit;
    }

    /** Проверка на DoS-подобное поведение */
    protected function checkDos(array $accessList = []): bool
    {
        $curTime = time();
        $entries = [];
        $f4 = F3::instance();
        $dos_interval = $f4->g('firewall.dos_max_hits',self::DOS_INTERVAL);
        $dos_max_hits = $f4->g('firewall.dos_max_hits',self::DOS_MAX_HITS);

        // IPS — анализ за последние N секунд
        $totalInPastRange = 0;
        if (isset($accessList[$this->ip])) {
            foreach ($accessList[$this->ip] as $timestamp => $count) {
                if ($curTime - $timestamp < $dos_interval || $curTime == $timestamp) {
                    $totalInPastRange += $count;
                }
            }
        }
        return $totalInPastRange > $dos_max_hits;
    }

    /** Метод блокировки с логированием */
    protected function updateAccessList(array &$accessList): void
    {
        $curTime = time();
        $suffix = date('H');

        if (!isset($accessList[$this->ip])) {
            $accessList[$this->ip] = [
                $curTime => 1
            ];
        } else{
            if(!empty($accessList[$this->ip][$curTime])){
                $accessList[$this->ip][$curTime]++;
            } else {
                $accessList[$this->ip][$curTime] = 1;
            }
        }
        $this->cache->set(self::PHPIDS_ACCESS_KEY.$suffix, self::CACHE_FOLDER, $accessList, 3600);
    }

    /** Метод блокировки с логированием */
    protected function block(string $reason): void
    {
        $banManager = IpBanManager::instance();
        if (!in_array($this->ip, $banManager->isBannedCache)) {
            $havecookie = !empty($_COOKIE) ? 1 : 0;
            $useragent = strip_tags(substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 100));
            $uri = substr($_SERVER['REQUEST_URI'] ?? '', 0, 100);
            $referer = substr($_SERVER['HTTP_REFERER'] ?? '', 0, 100);

            $banManager->banIp($this->ip, $banManager::STATUS_TEMP_HOUR);
            $logFile = $f4->get('log.firewall_log');
            if($logFile){
                $txt = sprintf(
                    "Firewall block from IP %s: Reason: %s\n cookie: %s\n agent: %s\n uri: %s\n referer: %s\n\n",
                    $this->ip,
                    $reason,
                    $havecookie,
                    $useragent,
                    $uri,
                    $referer
                );
                Log::writeIn($logFile, $txt, LogLevel::ERROR);
            }
        }

        http_response_code(403);
        exit("Access denied.");
    }

    /** Получить текущий IP-адрес */
    public static function getIpAddress(): string
    {
        return $_SERVER['HTTP_X_FORWARDED_FOR'] ??
               $_SERVER['HTTP_CLIENT_IP'] ??
               $_SERVER['REMOTE_ADDR'] ??
               getenv('HTTP_X_FORWARDED_FOR') ??
               getenv('HTTP_CLIENT_IP') ??
               getenv('REMOTE_ADDR') ??
               'unknown';
    }

    /** Вызов вручную из cron-скрипта для очистки бана */
    public static function cronCleanup(): void
    {
        $banManager = IpBanManager::instance();
        $banManager->unbanExpired();
    }
}