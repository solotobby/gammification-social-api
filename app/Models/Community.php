<?php

namespace App\Models;

use App\Support\CommunityFeeCalculator;
use App\Traits\UuidTrait;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Community extends Model
{
    use HasFactory, UuidTrait;

    protected $fillable = [
        'name',
        'slug',
        'currency',
        'community_categories_id',
        'image',
        'banner',
        'description',
        'type',
        'monthly_fee',
        'billing_type',
        'billing_interval',
        'fee_payer',
        'platform_fee_percent',
        'user_id',
        'archived_at',
    ];

    protected $casts = [
        'monthly_fee' => 'decimal:2',
        'platform_fee_percent' => 'integer',
        'archived_at' => 'datetime',
    ];

    public function setCurrencyAttribute(?string $value): void
    {
        $this->attributes['currency'] = $value ? strtoupper($value) : $value;
    }

    /** Display-only palette cycled deterministically per community — no extra column needed. */
    private const COLOR_PALETTE = ['#5A4FDC', '#1FAE64', '#EF4467', '#37A2F4', '#E3A421', '#9C7BF5'];

    /** Default cover gradients when no banner image is uploaded. */
    private const COVER_PALETTES = [
        ['#15103A', '#5A4FDC', '#1FAE64'],
        ['#1A0F2E', '#EF4467', '#E3A421'],
        ['#0F1A2E', '#37A2F4', '#5A4FDC'],
        ['#0F2E1A', '#1FAE64', '#9C7BF5'],
        ['#2E0F1A', '#EF4467', '#9C7BF5'],
        ['#1A2E2E', '#37A2F4', '#1FAE64'],
    ];

    /**
     * Communities are looked up by slug in routes (/community/{community}),
     * not by their UUID — matches the shareable public link built earlier.
     */
    public function getRouteKeyName(): string
    {
        return 'slug';
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function category()
    {
        return $this->belongsTo(CommunityCategory::class, 'community_categories_id');
    }

    public function posts()
    {
        return $this->hasMany(CommunityPost::class);
    }

    /**
     * Active members only. This is the relation used everywhere counts,
     * "is this person a member" checks, and the feed care about — a banned
     * member should never show up as a member anywhere in the app.
     */
    public function members()
    {
        return $this->belongsToMany(User::class, 'community_users')
            ->using(CommunityUser::class)
            ->wherePivot('status', 'active')
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    /**
     * All pivot rows regardless of status — used for leave/detach operations.
     */
    public function allMembers()
    {
        return $this->belongsToMany(User::class, 'community_users')
            ->using(CommunityUser::class)
            ->withPivot(['id', 'role', 'status'])
            ->withTimestamps();
    }

    public function bannedMembers()
    {
        return $this->belongsToMany(User::class, 'community_users')
            ->wherePivot('status', 'banned')
            ->withPivot(['role', 'status'])
            ->withTimestamps();
    }

    public function joinRequests()
    {
        return $this->hasMany(CommunityJoinRequest::class);
    }

    public function pendingJoinRequests()
    {
        return $this->joinRequests()->where('status', 'pending')->latest();
    }

    public function invites()
    {
        return $this->hasMany(CommunityInvite::class);
    }

    public function payouts()
    {
        return $this->hasMany(CommunityPayout::class);
    }

    public function subscriptions()
    {
        return $this->hasMany(CommunitySubscription::class);
    }

    public function paymentPlans()
    {
        return $this->hasMany(CommunityPaymentPlan::class);
    }

    public function isPrivate(): bool
    {
        return $this->type === 'private';
    }

    public function isArchived(): bool
    {
        return $this->archived_at !== null;
    }

    /**
     * Shareable landing page at /c/{slug}.
     * Available for every community type (public, private, paid, approval).
     */
    public function hasPublicPage(): bool
    {
        return ! $this->isArchived();
    }

    public function getPublicUrlAttribute(): string
    {
        return route('community.public', $this);
    }

    /**
     * Two-letter monogram for the community's icon tile, e.g.
     * "Side Hustle Naija" -> "SH".
     */
    public function getInitialsAttribute(): string
    {
        $words = preg_split('/\s+/', trim((string) $this->name));

        $first = mb_substr($words[0] ?? '', 0, 1);
        $second = mb_substr($words[1] ?? '', 0, 1);

        return mb_strtoupper($first.$second) ?: '—';
    }

    /**
     * A stable brand color for the icon tile, deterministic per community
     * (same community always renders the same color, no DB column needed).
     */
    public function getColorAttribute(): string
    {
        $palette = self::COLOR_PALETTE;

        return $palette[crc32((string) $this->id) % count($palette)];
    }

    /**
     * Deterministic default hero/cover background when no banner image exists.
     */
    public function getCoverGradientAttribute(): string
    {
        $palette = self::COVER_PALETTES[crc32((string) $this->id) % count(self::COVER_PALETTES)];

        return "linear-gradient(135deg, {$palette[0]} 0%, {$palette[1]} 52%, {$palette[2]} 130%)";
    }

    /**
     * What a member actually pays, per charge.
     *
     * If the creator absorbs the platform fee, members pay the list price and
     * the fee is deducted from the creator's payout. If members bear the cost,
     * the fee ({list × rate}) is added on top so the creator still receives
     * the full list price.
     */
    public function getMemberChargeAttribute(): ?float
    {
        return $this->feeBreakdown()['memberCharge'] ?? null;
    }

    public function getPlatformFeeAmountAttribute(): ?float
    {
        return $this->feeBreakdown()['platformCut'] ?? null;
    }

    public function getCreatorPayoutAttribute(): ?float
    {
        return $this->feeBreakdown()['creatorPayout'] ?? null;
    }

    private function feeBreakdown(): ?array
    {
        if ($this->type !== 'paid' || is_null($this->monthly_fee)) {
            return null;
        }

        return CommunityFeeCalculator::breakdown(
            (float) $this->monthly_fee,
            (int) ($this->platform_fee_percent ?? 0),
            (string) $this->fee_payer,
        );
    }

    /**
     * Short, human-readable billing description, e.g. "One-off payment",
     * "Billed monthly", "Billed annually" — used anywhere a price is shown
     * (cards, hero, about tab) so the phrasing never drifts out of sync.
     */
    public function getBillingLabelAttribute(): ?string
    {
        if ($this->type !== 'paid') {
            return null;
        }

        if ($this->billing_type === 'one_off') {
            return 'One-off payment';
        }

        $adverb = config("community.billing_intervals.{$this->billing_interval}.adverb", $this->billing_interval);

        return $this->billing_interval ? "Billed {$adverb}" : 'Subscription';
    }

    /**
     * The short suffix appended to a formatted price, e.g. "/mo", "/yr",
     * or empty for a one-off payment.
     */
    public function getPriceSuffixAttribute(): string
    {
        if ($this->billing_type === 'one_off') {
            return '';
        }

        return config("community.billing_intervals.{$this->billing_interval}.suffix", '');
    }

    /**
     * Normalise a currency code for comparisons (defaults missing values to NGN).
     */
    public static function normaliseCurrency(?string $currency): string
    {
        return strtoupper((string) ($currency ?: 'NGN'));
    }

    /**
     * Whether this community is priced in the given user's wallet currency.
     */
    public function isInCurrency(?string $currency = null): bool
    {
        $currency ??= userBaseCurrency();

        return self::normaliseCurrency($this->currency) === self::normaliseCurrency($currency);
    }

    /**
     * Limit discovery lists to communities in the viewer's wallet currency.
     */
    public function scopeForUserCurrency($query, ?string $currency = null)
    {
        $currency = self::normaliseCurrency($currency ?? userBaseCurrency());

        return $query->where('currency', $currency);
    }
}