<?php
declare(strict_types=1);
namespace PESM\Domain\Allocation;

/** Separate credential domains prevent person/group and legacy QR confusion. */
final class Credential
{
    public static function create(string $kind,string $id,int $version=1): string
    {
        if(!in_array($kind,['P','G'],true)||!preg_match('/^[a-f0-9]{32}$/D',$id)||$version<1){Policy::fail('invalid_credential',422);}
        $prefix='A.'.$kind.'.'.$id;
        return $prefix.'.'.hash_hmac('sha256','pesm:allocated:'.$prefix.':'.$version,wp_salt('secure_auth'));
    }
    public static function parse(string $payload): array
    {
        if(!preg_match('/^A\.([PG])\.([a-f0-9]{32})\.([a-f0-9]{64})$/D',$payload,$m)){Policy::fail('invalid_qr',422);}
        return [$m[1],$m[2]];
    }
    public static function valid(string $payload,string $kind,string $id,int $version): bool
    {
        return hash_equals(self::create($kind,$id,$version),$payload);
    }
    public static function number(string $kind,string $id): string {return 'A-'.$kind.'-'.strtoupper($id);}
}
