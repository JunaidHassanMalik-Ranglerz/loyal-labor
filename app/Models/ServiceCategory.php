<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ServiceCategory extends Model
{
    protected $table = 'service_categories';

    protected $guarded = [];

    protected $casts = [
        'is_active' => 'boolean',
        'sort_order' => 'integer',
    ];

    public function iconUrl(): ?string
    {
        $icon = (string) ($this->icon ?? '');
        if ($icon === '') {
            return null;
        }
        if (str_starts_with($icon, 'http://') || str_starts_with($icon, 'https://')) {
            return $icon;
        }
        if (str_starts_with($icon, 'fa-')) {
            return null;
        }

        return asset($icon);
    }

    public function technicians()
    {
        return $this->belongsToMany(
            User::class,
            'service_category_user',
            'service_category_id',
            'user_id'
        )->withTimestamps();
    }

    public function activeTechnicians()
    {
        return $this->technicians()
            ->where('users.user_type', 'technician')
            ->whereAccountActivated();
    }
}
