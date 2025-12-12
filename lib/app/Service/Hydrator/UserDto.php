<?php
namespace App\Service\Hydrator;

use App\Service\Hydrator\DtoBase;

class UserDto extends DtoBase
{
    public int $id;
    public string $login;
    public string $email;
    public ?string $created_at = null;
}
