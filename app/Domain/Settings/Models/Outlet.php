<?php

namespace App\Domain\Settings\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * @property int $id
 * @property string $name
 * @property string|null $address
 * @property string $timezone
 * @property string|null $npwp
 * @property string|null $logo_path
 * @property string|null $banner_path
 * @property string|null $tagline
 * @property string|null $opening_hours
 * @property string|null $phone
 * @property string|null $instagram
 * @property string|null $wifi_name
 * @property string|null $wifi_password
 * @property string|null $about
 * @property string|null $whatsapp
 * @property string|null $tiktok
 * @property string|null $facebook
 * @property string|null $website
 * @property string|null $maps_url
 * @property string|null $google_review_url
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Outlet extends Model
{
    protected $guarded = [];
}
