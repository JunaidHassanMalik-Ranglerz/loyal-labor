<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Subscription extends Model
{
    public const PLAN_TYPES = [
        'basic_plan' => 'Basic',
        'silver_plan' => 'Silver',
        'golden_plan' => 'Gold',
    ];

    public const DURATION_UNITS = [
        'days' => 'Days',
        'months' => 'Months',
    ];

    /**
     * Locked feature catalog per plan. Admin cannot invent custom features.
     */
    public const STATIC_FEATURES = [
        'basic_plan' => [
            ['key' => 'pay_to_view_booking_details', 'label' => 'Pay to view booking details'],
            ['key' => 'verified_technician_badge', 'label' => 'Verified technician badge'],
            ['key' => 'enable_customer_reviews_ratings', 'label' => 'Enable customer reviews & ratings'],
        ],
        'silver_plan' => [
            ['key' => 'profile_featured', 'label' => 'Profile featured for 7 days'],
            ['key' => 'increased_profile_visibility', 'label' => 'Increased profile visibility'],
            ['key' => 'verified_badge', 'label' => 'Verified badge'],
            ['key' => 'priority_search_placement', 'label' => 'Priority search placement'],
            ['key' => 'more_customer_reach', 'label' => 'More customer reach'],
        ],
        'golden_plan' => [
            ['key' => 'profile_featured', 'label' => 'Profile featured for 15 days'],
            ['key' => 'increased_profile_visibility', 'label' => 'Increased profile visibility'],
            ['key' => 'verified_badge', 'label' => 'Verified badge'],
            ['key' => 'priority_search_placement', 'label' => 'Priority search placement'],
            ['key' => 'more_customer_reach', 'label' => 'More customer reach'],
        ],
    ];

    protected $guarded = [];

    protected $casts = [
        'features' => 'array',
        'is_active' => 'boolean',
        'price_pkr' => 'decimal:2',
        'saving_price' => 'decimal:2',
        'original_price' => 'decimal:2',
        'discount_percentage' => 'decimal:2',
        'payable_price' => 'decimal:2',
        'discount_percent' => 'float',
        'tax_percent' => 'integer',
    ];

    public static function planTypeKeys(): array
    {
        return array_keys(self::PLAN_TYPES);
    }

    public static function featuresForPlanType(string $planType): array
    {
        return self::STATIC_FEATURES[$planType] ?? [];
    }

    public static function featureLabelsForPlanType(string $planType): array
    {
        return array_values(array_map(
            fn (array $feature) => $feature['label'],
            self::featuresForPlanType($planType)
        ));
    }

    public static function featureKeysForPlanType(string $planType): array
    {
        return array_values(array_map(
            fn (array $feature) => $feature['key'],
            self::featuresForPlanType($planType)
        ));
    }

    public static function calculatePayablePrice(float $originalPrice, float $discountPercentage): float
    {
        $originalPrice = max(0, round($originalPrice, 2));
        $discountPercentage = min(100, max(0, round($discountPercentage, 2)));
        $payable = $originalPrice - ($originalPrice * $discountPercentage / 100);

        return round(max(0, $payable), 2);
    }

    /**
     * Build canonical pricing payload from admin input.
     * Never trusts a frontend-submitted payable price.
     */
    public static function buildPricingPayload(float $originalPrice, float $discountPercentage): array
    {
        $originalPrice = max(0, round($originalPrice, 2));
        $discountPercentage = min(100, max(0, round($discountPercentage, 2)));
        $payablePrice = self::calculatePayablePrice($originalPrice, $discountPercentage);

        return [
            'original_price' => $originalPrice,
            'discount_percentage' => $discountPercentage,
            'payable_price' => $payablePrice,
            // Legacy columns kept in sync.
            'price_pkr' => $originalPrice,
            'discount_percent' => (int) round($discountPercentage),
            'saving_price' => $payablePrice,
        ];
    }

    public function resolvedOriginalPrice(): float
    {
        $original = $this->original_price;
        if ($original === null || $original === '') {
            $original = $this->price_pkr;
        }

        return round(max(0, (float) $original), 2);
    }

    public function storedPayablePrice(): ?float
    {
        if ($this->payable_price !== null && $this->payable_price !== '') {
            return round(max(0, (float) $this->payable_price), 2);
        }

        $saving = (float) ($this->saving_price ?? 0);
        if ($saving > 0) {
            return round($saving, 2);
        }

        return null;
    }

    public function resolvedDiscountPercentage(): float
    {
        $original = $this->resolvedOriginalPrice();
        $storedPayable = $this->storedPayablePrice();
        $storedDiscount = null;

        if ($this->discount_percentage !== null && $this->discount_percentage !== '') {
            $storedDiscount = round(min(100, max(0, (float) $this->discount_percentage)), 2);
        } elseif ($this->discount_percent !== null && $this->discount_percent !== '') {
            $storedDiscount = round(min(100, max(0, (float) $this->discount_percent)), 2);
        }

        // Heal legacy rows where discount is 0/missing but an old saving/payable price exists.
        if ($original > 0 && $storedPayable !== null && $storedPayable <= $original) {
            $fromPrices = round((($original - $storedPayable) / $original) * 100, 2);
            $fromFormula = self::calculatePayablePrice($original, (float) ($storedDiscount ?? 0));

            if ($storedDiscount === null || abs($fromFormula - $storedPayable) > 0.009) {
                return min(100, max(0, $fromPrices));
            }
        }

        if ($storedDiscount !== null) {
            return $storedDiscount;
        }

        return 0.0;
    }

    public function resolvedPayablePrice(): float
    {
        // Always calculate from original + discount. Never trust a stale payable/saving value alone.
        return self::calculatePayablePrice(
            $this->resolvedOriginalPrice(),
            $this->resolvedDiscountPercentage()
        );
    }

    public function getPlanTypeLabelAttribute(): string
    {
        return self::PLAN_TYPES[$this->plan_type] ?? ucwords(str_replace('_', ' ', (string) $this->plan_type));
    }

    public function getDurationUnitAttribute($value): string
    {
        $unit = $value ?: 'months';

        return in_array($unit, ['days', 'months'], true) ? $unit : 'months';
    }

    public function getDurationValueAttribute(): int
    {
        return max(1, (int) ($this->duration_months ?: 1));
    }

    public function getDurationLabelAttribute(): string
    {
        $value = $this->duration_value;
        $unit = $this->duration_unit;

        if ($unit === 'days') {
            return $value . ' ' . ($value === 1 ? 'Day' : 'Days');
        }

        return $value . ' ' . ($value === 1 ? 'Month' : 'Months');
    }

    public function endsAtFrom($from = null): \Carbon\Carbon
    {
        $from = $from ? \Carbon\Carbon::parse($from) : now();
        $value = $this->duration_value;

        return $this->duration_unit === 'days'
            ? $from->copy()->addDays($value)
            : $from->copy()->addMonths($value);
    }

    public function resolvedFeatureLabels(): array
    {
        $planType = $this->plan_type ?? 'basic_plan';
        $locked = self::featureLabelsForPlanType($planType);
        if (!empty($locked)) {
            return $locked;
        }

        $features = is_array($this->features) ? $this->features : json_decode($this->features ?? '[]', true);
        if (!is_array($features)) {
            $features = array_filter(array_map('trim', explode(',', (string) $this->features)));
        }

        return array_values($features);
    }

    public function toApiArray(): array
    {
        $planType = $this->plan_type ?? 'basic_plan';
        $originalPrice = $this->resolvedOriginalPrice();
        $discountPercentage = $this->resolvedDiscountPercentage();
        $payablePrice = $this->resolvedPayablePrice();

        $discountAmount = round(max(0, $originalPrice - $payablePrice), 2);

        return [
            'id' => $this->id,
            'name' => $this->name ?: ($this->plan_type_label),
            'plan_name' => $this->name ?: ($this->plan_type_label),
            'plan_type' => $planType,
            'plan_type_label' => $this->plan_type_label,
            'duration' => $this->duration_value,
            'duration_months' => $this->duration_value,
            'duration_unit' => $this->duration_unit,
            'duration_label' => $this->duration_label,
            // Primary pricing fields (use these in app UI).
            'original_price' => $originalPrice,
            'discount_percentage' => $discountPercentage,
            'discount_amount' => $discountAmount,
            'payable_price' => $payablePrice,
            'currency' => 'PKR',
            'status' => $this->is_active ? 'active' : 'inactive',
            // Legacy keys (compat only):
            // price_pkr = original_price
            // saving_price = payable_price (NOT "discount amount")
            // discount_percent = discount_percentage
            'price_pkr' => $originalPrice,
            'saving_price' => $payablePrice,
            'discount_percent' => $discountPercentage,
            'tax_percent' => 0,
            'features' => $this->resolvedFeatureLabels(),
            'feature_keys' => self::featureKeysForPlanType($planType),
            'is_active' => (bool) $this->is_active,
        ];
    }

    public function toOrderSummaryArray(): array
    {
        $plan = $this->toApiArray();
        $originalPrice = (float) $plan['original_price'];
        $payablePrice = (float) $plan['payable_price'];
        $discountPercentage = (float) $plan['discount_percentage'];

        if ($payablePrice < 0 || $payablePrice > $originalPrice) {
            $payablePrice = self::calculatePayablePrice($originalPrice, $discountPercentage);
        }

        $discountAmount = round(max(0, $originalPrice - $payablePrice), 2);
        $taxPercent = 0;
        $taxAmount = 0.0;
        $total = $payablePrice;
        $money = static fn (float $amount): string => 'PKR ' . number_format($amount, 2);

        $name = (string) $plan['name'];
        $durationLabel = (string) $plan['duration_label'];
        $planLabel = trim($name . ($durationLabel !== '' ? ' (' . $durationLabel . ')' : ''));

        return [
            'id' => $plan['id'],
            'badge' => strtoupper((string) $plan['plan_type_label']) . ' PLAN',
            'name' => $name,
            'plan_name' => $name,
            'plan_type' => $plan['plan_type'],
            'plan_type_label' => $plan['plan_type_label'],
            'duration' => $plan['duration'],
            'duration_unit' => $plan['duration_unit'],
            'duration_label' => $durationLabel,
            'original_price' => $originalPrice,
            'original_price_label' => $money($originalPrice),
            'discount_percentage' => $discountPercentage,
            'payable_price' => $payablePrice,
            'payable_price_label' => $money($payablePrice),
            'currency' => 'PKR',
            'save_amount' => $discountAmount,
            'save_label' => 'Save ' . $money($discountAmount),
            'features' => $plan['features'],
            'feature_keys' => $plan['feature_keys'],
            'price_breakdown' => [
                'plan_label' => $planLabel,
                'plan_price' => $originalPrice,
                'plan_price_label' => $money($originalPrice),
                'discount_percent' => $discountPercentage,
                'discount_percentage' => $discountPercentage,
                'discount_amount' => $discountAmount,
                'discount_label' => 'Discount (' . rtrim(rtrim(number_format($discountPercentage, 2, '.', ''), '0'), '.') . '%)',
                'discount_amount_label' => '- ' . $money($discountAmount),
                'tax_percent' => $taxPercent,
                'tax_amount' => $taxAmount,
                'tax_label' => 'Tax (' . $taxPercent . '%)',
                'tax_amount_label' => $money($taxAmount),
                'total' => $total,
                'total_label' => $money($total),
            ],
            'payment_methods' => [
                ['key' => 'bank_transfer', 'label' => 'Bank Transfer'],
                ['key' => 'easypaisa', 'label' => 'EasyPaisa'],
            ],
            'cancellation_policy' => [
                'title' => 'Cancellation Policy',
                'message' => 'Non-refundable after 24 hours of activation.',
            ],
        ];
    }
}
