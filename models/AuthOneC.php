<?php namespace Lovata\BaseCode\Models;

use Kharanenka\Scope\CodeField;
use Model;
use October\Rain\Argon\Argon;
use October\Rain\Database\ModelException;

/**
 * Class AuthOneC
 *
 * @package Lovata\BaseCode\Models
 * @author Sergey Zakharevich, <s.v.zakharevich@gmail.com>, LOVATA Group
 *
 * @mixin \October\Rain\Database\Builder
 * @mixin \Eloquent
 *
 * @property integer $id
 * @property bool $code
 * @property string $value
 *
 * @method static $this getByValue($sValue)
 */
class AuthOneC extends Model
{
    use CodeField;

    /** @var string */
    public $table = 'lovata_basecode_auth_one_c';
    /** @var string */
    public $fillable = [
        'code',
        'value',
    ];
    /** @var array */
    public $attributeNames = [
        'name' => 'lovata.toolbox::lang.field.code',
        'value' => 'lovata.basecode::lang.field.value',
    ];
    /** @var array */
    public $rules = [
        'code' => 'required',
        'value' => 'required|unique:lovata_basecode_auth_one_c',
    ];

    /**
     * Generate cookie value.
     * @param string $sCode
     * @return AuthOneC|null
     */
    public static function generate($sCode)
    {
        if (empty($sCode)) {
            return null;
        }

        $sDate = Argon::now()->toDateTimeString();
        $sNumber = mt_rand(5000, 10000);
        $iCount = 0;

        $obAuth = null;

        while (empty($obAuth)) {
            ++$iCount;
            $sValue = $sDate . $sNumber . $iCount;
            $sValue = md5($sValue);
            $obAuth = self::createAuth($sCode, $sValue);
        }

        return $obAuth;
    }

    /**
     * Create auth.
     * @param $sCode
     * @param $sValue
     * @return AuthOneC|null
     */
    protected static function createAuth($sCode, $sValue)
    {
        $obAuth = AuthOneC::getByCode($sCode)->getByValue($sValue)->first();

        if (!empty($obAuth)) {
            return null;
        }

        $arData = [
            'code' => $sCode,
            'value' => $sValue,
        ];

        try {
            $obAuth = AuthOneC::create($arData);
        } catch (ModelException $obException) {
            return null;
        }

        return $obAuth;
    }

    /**
     * Get by value.
     * @param \Illuminate\Database\Eloquent\Builder|\October\Rain\Database\Builder $obQuery
     * @param string $sValue
     * @return \Illuminate\Database\Eloquent\Builder|\October\Rain\Database\Builder;
     */
    public function scopeGetByValue($obQuery, $sValue)
    {
        return $obQuery->where('value', $sValue);
    }
}
