<?php

namespace App\Utils\Security;

use App\Service\DataManager;
use App\Service\DataManagerRegistry;
use App\Service\Data\IpBanData;
use DateTime;
use DateInterval;
use App\Base\Prefab;

class IpBanManager extends Prefab
{
    protected IpBanData $ip_ban;
    public array $isBannedCache = []; 
    const STATUS_TEMP_HOUR = 1;
    const STATUS_TEMP_DAY = 2;
    const STATUS_PERMANENT = 3;

    public function __construct()
    {
        $this->ip_ban = DataManagerRegistry::get(IpBanData::class);
    }

    public function banIp(string $ip, int $status = self::STATUS_TEMP_HOUR): bool
    {
        $expiresAt = null;

        if ($status === self::STATUS_TEMP_HOUR) {
            $expiresAt = (new DateTime())->add(new DateInterval('PT1H'));
        } elseif ($status === self::STATUS_TEMP_DAY) {
            $expiresAt = (new DateTime())->add(new DateInterval('P1D'));
        }

        return $this->ip_ban->add([
            'ip' => $ip,
            'status' => $status,
            'banned_at' => (new DateTime())->format('Y-m-d H:i:s'),
            'expires_at' => $expiresAt?->format('Y-m-d H:i:s'),
        ]);
    }

    public function isBanned(string $ip): bool
    {
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $table = $this->ip_ban::getTableName();
        $is_banned = (bool) $this->ip_ban->getRaw(
            "SELECT 1 FROM {$table} WHERE ip = ? AND (expires_at IS NULL OR expires_at > ?) LIMIT 1",
            [$ip, $now]
        );
        if($is_banned){
            $this->isBannedCache[] = $ip;
        }
        return $is_banned;
    }

    public function unbanExpired(): void
    {
        $table = $this->ip_ban::getTableName();
        $now = (new DateTime())->format('Y-m-d H:i:s');
        $this->ip_ban->getRaw("DELETE FROM {$table} WHERE expires_at IS NOT NULL AND expires_at <= ?", [$now]);
    }
}
