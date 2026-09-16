<?php

namespace App\Services;

use App\Models\PaykoinTransaction;
use App\Models\Post;
use App\Models\PostBoost;
use App\Models\PostBoostClick;
use App\Models\SystemSetting;
use App\Models\User;
use App\Models\Wallet;
use App\Notifications\GeneralNotification;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;
use RuntimeException;

class PostBoostService
{
    public const RATE_PER_CLICK = 3; // 3 PayKoin / click
    public const FIAT_PER_PK = 10;   // ₦10 / PayKoin
    public const MIN_CLICKS = 10;

    public const STANDARD_CTAS = [
        'Shop Now',
        'Learn More',
        'Sign Up',
        'Visit Website',
        'Download',
        'Contact Us',
        'Book Now',
    ];

    /**
     * Get campaign configuration, rates, CTAs, and user spendable PayKoin.
     */
    public function getBoostConfig(User $user, Post $post): array
    {
        $wallet = Wallet::where('user_id', $user->id)->first();
        $spendablePk = (int) ($wallet?->paykoin_spendable ?? 0);

        return [
            'boost_enabled' => SystemSetting::isBoostEnabled(),
            'is_boost_enabled' => SystemSetting::isBoostEnabled(),
            'rate_pk_per_click' => self::RATE_PER_CLICK,
            'rate_per_click_paykoin' => self::RATE_PER_CLICK,
            'fiat_per_pk' => self::FIAT_PER_PK,
            'rate_fiat_per_click' => self::RATE_PER_CLICK * self::FIAT_PER_PK,
            'min_clicks' => self::MIN_CLICKS,
            'user_spendable_pk' => $spendablePk,
            'user_spendable_paykoin' => $spendablePk,
            'currency' => $wallet?->currency ?? 'NGN',
            'cta_options' => self::STANDARD_CTAS,
            'packages' => [
                ['clicks' => 50, 'pk_cost' => 50 * self::RATE_PER_CLICK, 'fiat_cost' => 50 * self::RATE_PER_CLICK * self::FIAT_PER_PK],
                ['clicks' => 100, 'pk_cost' => 100 * self::RATE_PER_CLICK, 'fiat_cost' => 100 * self::RATE_PER_CLICK * self::FIAT_PER_PK],
                ['clicks' => 250, 'pk_cost' => 250 * self::RATE_PER_CLICK, 'fiat_cost' => 250 * self::RATE_PER_CLICK * self::FIAT_PER_PK],
                ['clicks' => 500, 'pk_cost' => 500 * self::RATE_PER_CLICK, 'fiat_cost' => 500 * self::RATE_PER_CLICK * self::FIAT_PER_PK],
            ],
            'post' => [
                'id' => $post->id,
                'content' => $post->content,
                'is_boosted' => (bool) $post->is_boosted,
                'media_status' => $post->media_status,
                'has_images' => (bool) $post->has_images,
                'has_video' => (bool) $post->has_video,
            ],
        ];
    }

    /**
     * Launch a new post boost campaign.
     *
     * @param  array{target_url: string, cta: string, clicks: int, platform_payhankey?: bool, platform_partner?: bool}  $data
     */
    public function createBoost(User $user, Post $post, array $data): PostBoost
    {
        if (! SystemSetting::isBoostEnabled()) {
            throw new RuntimeException('Post boosting is currently unavailable.');
        }

        if ((string) $post->user_id !== (string) $user->id) {
            throw new InvalidArgumentException('You can only boost your own posts.');
        }

        if ($post->activeBoost()->exists() || PostBoost::where('post_id', $post->id)->where('status', 'active')->where('remaining_clicks', '>', 0)->exists()) {
            throw new InvalidArgumentException('This post already has an active boost campaign.');
        }

        $targetUrl = trim($data['target_url'] ?? '');
        if (empty($targetUrl) || ! filter_var($targetUrl, FILTER_VALIDATE_URL)) {
            throw new InvalidArgumentException('Please provide a valid destination URL.');
        }

        $cta = trim($data['cta'] ?? 'Shop Now');
        $clicks = (int) ($data['clicks'] ?? self::MIN_CLICKS);

        if ($clicks < self::MIN_CLICKS) {
            throw new InvalidArgumentException('Minimum click package is ' . self::MIN_CLICKS . ' clicks.');
        }

        $platformPayhankey = isset($data['platform_payhankey']) ? (bool) $data['platform_payhankey'] : true;
        $platformPartner = isset($data['platform_partner']) ? (bool) $data['platform_partner'] : true;

        if (! $platformPayhankey && ! $platformPartner) {
            throw new InvalidArgumentException('At least one platform must be selected.');
        }

        $pkCost = $clicks * self::RATE_PER_CLICK;
        $ref = generateTransactionRef('BST');

        return DB::transaction(function () use (
            $user,
            $post,
            $targetUrl,
            $cta,
            $clicks,
            $pkCost,
            $platformPayhankey,
            $platformPartner,
            $ref
        ) {
            $wallet = Wallet::where('user_id', $user->id)->lockForUpdate()->first();

            if (! $wallet) {
                throw new RuntimeException('User wallet not found.');
            }

            if ((int) $wallet->paykoin_spendable < $pkCost) {
                throw new InvalidArgumentException("Insufficient PayKoin balance. You need {$pkCost} PK but only have {$wallet->paykoin_spendable} PK.");
            }

            // Debit user spendable PayKoin
            $wallet->paykoin_spendable = (int) $wallet->paykoin_spendable - $pkCost;
            $wallet->save();

            // Record Paykoin transaction audit ledger
            PaykoinTransaction::create([
                'user_id' => $user->id,
                'type' => 'post_boost',
                'pk_amount' => -$pkCost,
                'fiat_amount' => (float) ($pkCost * self::FIAT_PER_PK),
                'currency' => $wallet->currency ?: 'NGN',
                'ref' => $ref,
                'description' => "Boosted post for {$clicks} guaranteed clicks",
                'meta' => [
                    'post_id' => $post->id,
                    'clicks' => $clicks,
                    'rate_pk' => self::RATE_PER_CLICK,
                    'target_url' => $targetUrl,
                    'cta' => $cta,
                    'platform_payhankey' => $platformPayhankey,
                    'platform_partner' => $platformPartner,
                ],
            ]);

            // Create Post Boost record
            $boost = PostBoost::create([
                'post_id' => $post->id,
                'user_id' => $user->id,
                'target_url' => $targetUrl,
                'cta' => $cta,
                'total_clicks' => $clicks,
                'delivered_clicks' => 0,
                'remaining_clicks' => $clicks,
                'pk_cost' => $pkCost,
                'rate_pk' => self::RATE_PER_CLICK,
                'platform_payhankey' => $platformPayhankey,
                'platform_partner' => $platformPartner,
                'status' => 'active',
                'ref' => $ref,
            ]);

            // Update post status: flagged as boosted and creator monetization paused
            $post->is_boosted = true;
            $post->monetization_paused = true;
            $post->save();

            try {
                $user->notify(new GeneralNotification([
                    'title' => 'Post Boost Launched',
                    'message' => "Your campaign for {$clicks} clicks is now live!",
                    'icon' => 'fa-rocket text-primary',
                    'type' => 'post_boosted',
                    'meta' => [
                        'post_id' => $post->id,
                        'boost_id' => $boost->id,
                    ],
                ]));
            } catch (\Throwable $e) {
                // notification failure shouldn't abort transaction
            }

            return $boost;
        });
    }

    /**
     * List user's boost campaigns.
     */
    public function listUserBoosts(User $user, ?string $status = null, int $perPage = 15): LengthAwarePaginator
    {
        $query = PostBoost::query()
            ->where('user_id', $user->id)
            ->with(['post:id,content,media_status,has_video,has_images,views,clicks,likes,created_at'])
            ->when($status, fn ($q) => $q->where('status', strtolower($status)))
            ->latest();

        $paginator = $query->paginate($perPage);

        $paginator->getCollection()->transform(function (PostBoost $boost) {
            return [
                'id' => $boost->id,
                'post_id' => $boost->post_id,
                'target_url' => $boost->target_url,
                'cta' => $boost->cta,
                'total_clicks' => $boost->total_clicks,
                'delivered_clicks' => $boost->delivered_clicks,
                'remaining_clicks' => $boost->remaining_clicks,
                'progress_percent' => $boost->total_clicks > 0
                    ? round(($boost->delivered_clicks / $boost->total_clicks) * 100, 1)
                    : 0,
                'pk_cost' => $boost->pk_cost,
                'rate_pk' => $boost->rate_pk,
                'platform_payhankey' => $boost->platform_payhankey,
                'platform_partner' => $boost->platform_partner,
                'status' => $boost->status,
                'ref' => $boost->ref,
                'created_at' => $boost->created_at?->toIso8601String(),
                'post' => $boost->post ? [
                    'id' => $boost->post->id,
                    'content' => Str::limit($boost->post->content, 120),
                    'media_status' => $boost->post->media_status,
                    'has_video' => (bool) $boost->post->has_video,
                    'has_images' => (bool) $boost->post->has_images,
                ] : null,
            ];
        });

        return $paginator;
    }

    /**
     * Get detailed analytics for a single campaign.
     */
    public function getBoostDetails(User $user, string $boostId): array
    {
        $boost = PostBoost::query()
            ->where('id', $boostId)
            ->where('user_id', $user->id)
            ->with(['post.user', 'post.images', 'post.video'])
            ->firstOrFail();

        $clicks = PostBoostClick::where('post_boost_id', $boost->id)->get();

        $devices = $clicks->groupBy('device')->map->count();
        $browsers = $clicks->groupBy('browser')->map->count();
        $platforms = $clicks->groupBy('platform')->map->count();

        return [
            'boost' => [
                'id' => $boost->id,
                'post_id' => $boost->post_id,
                'target_url' => $boost->target_url,
                'cta' => $boost->cta,
                'total_clicks' => $boost->total_clicks,
                'delivered_clicks' => $boost->delivered_clicks,
                'remaining_clicks' => $boost->remaining_clicks,
                'pk_cost' => $boost->pk_cost,
                'status' => $boost->status,
                'ref' => $boost->ref,
                'created_at' => $boost->created_at?->toIso8601String(),
            ],
            'analytics' => [
                'total_clicks_recorded' => $clicks->count(),
                'by_device' => $devices,
                'by_browser' => $browsers,
                'by_platform' => $platforms,
            ],
            'post' => [
                'id' => $boost->post->id,
                'content' => $boost->post->content,
                'is_boosted' => (bool) $boost->post->is_boosted,
            ],
        ];
    }

    /**
     * Pause an active boost campaign.
     */
    public function pauseBoost(User $user, string $boostId): PostBoost
    {
        $boost = PostBoost::where('id', $boostId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($boost->status !== 'active') {
            throw new InvalidArgumentException("Cannot pause a campaign with status '{$boost->status}'.");
        }

        $boost->status = 'paused';
        $boost->save();

        // Check if other active boosts remain on this post
        $hasOtherActive = PostBoost::where('post_id', $boost->post_id)
            ->where('id', '!=', $boost->id)
            ->where('status', 'active')
            ->where('remaining_clicks', '>', 0)
            ->exists();

        if (! $hasOtherActive) {
            Post::where('id', $boost->post_id)->update(['is_boosted' => false]);
        }

        return $boost;
    }

    /**
     * Resume a paused boost campaign.
     */
    public function resumeBoost(User $user, string $boostId): PostBoost
    {
        $boost = PostBoost::where('id', $boostId)
            ->where('user_id', $user->id)
            ->firstOrFail();

        if ($boost->status !== 'paused') {
            throw new InvalidArgumentException("Cannot resume a campaign with status '{$boost->status}'.");
        }

        if ($boost->remaining_clicks <= 0) {
            throw new InvalidArgumentException('This campaign has completed all clicks.');
        }

        $boost->status = 'active';
        $boost->save();

        Post::where('id', $boost->post_id)->update([
            'is_boosted' => true,
            'monetization_paused' => true,
        ]);

        return $boost;
    }

    /**
     * Record a click from a user or visitor, logging location, device, and browser.
     */
    public function recordClick(PostBoost $boost, Request $request, string $platform = 'payhankey'): string
    {
        $targetUrl = $boost->target_url;

        if ($boost->status !== 'active' || $boost->remaining_clicks <= 0) {
            return $targetUrl;
        }

        $ip = $request->getClientIp();
        $ua = (string) $request->userAgent();
        $deviceInfo = $this->parseUserAgent($ua);

        DB::transaction(function () use ($boost, $request, $platform, $ip, $deviceInfo, $ua) {
            $lockedBoost = PostBoost::where('id', $boost->id)->lockForUpdate()->first();

            if (! $lockedBoost || $lockedBoost->status !== 'active' || $lockedBoost->remaining_clicks <= 0) {
                return;
            }

            PostBoostClick::create([
                'post_boost_id' => $lockedBoost->id,
                'post_id' => $lockedBoost->post_id,
                'user_id' => $request->user()?->id,
                'platform' => in_array($platform, ['payhankey', 'partner'], true) ? $platform : 'payhankey',
                'ip' => $ip,
                'device' => $deviceInfo['device'],
                'browser' => $deviceInfo['browser'],
                'os' => $deviceInfo['os'],
                'user_agent' => Str::limit($ua, 500),
                'referrer' => Str::limit((string) $request->header('referer'), 500),
            ]);

            $lockedBoost->delivered_clicks += 1;
            $lockedBoost->remaining_clicks = max(0, $lockedBoost->remaining_clicks - 1);

            Post::where('id', $lockedBoost->post_id)->increment('clicks');

            if ($lockedBoost->remaining_clicks <= 0) {
                $lockedBoost->status = 'completed';

                $hasOtherActive = PostBoost::where('post_id', $lockedBoost->post_id)
                    ->where('id', '!=', $lockedBoost->id)
                    ->where('status', 'active')
                    ->where('remaining_clicks', '>', 0)
                    ->exists();

                if (! $hasOtherActive) {
                    Post::where('id', $lockedBoost->post_id)->update([
                        'is_boosted' => false,
                        'monetization_paused' => false,
                    ]);
                }

                try {
                    $creator = User::find($lockedBoost->user_id);
                    $creator?->notify(new GeneralNotification([
                        'title' => 'Post Boost Completed',
                        'message' => "Your campaign for {$lockedBoost->total_clicks} clicks has completed!",
                        'icon' => 'fa-check-circle text-success',
                        'type' => 'post_boost_completed',
                        'meta' => [
                            'boost_id' => $lockedBoost->id,
                            'post_id' => $lockedBoost->post_id,
                        ],
                    ]));
                } catch (\Throwable $e) {
                    // notification failure
                }
            }

            $lockedBoost->save();
        });

        return $targetUrl;
    }

    /**
     * Parse User-Agent string to extract device type, browser, and OS.
     *
     * @return array{device: string, browser: string, os: string}
     */
    public function parseUserAgent(?string $ua): array
    {
        $ua = (string) $ua;

        $device = 'Desktop';
        if (preg_match('/(tablet|ipad|playbook|silk)|(android(?!.*mobi))/i', $ua)) {
            $device = 'Tablet';
        } elseif (preg_match('/Mobile|Android|iP(hone|od)|IEMobile|BlackBerry|Kindle/i', $ua)) {
            $device = 'Mobile';
        }

        $os = 'Unknown OS';
        if (preg_match('/iphone/i', $ua)) {
            $os = 'iOS (iPhone)';
        } elseif (preg_match('/ipad/i', $ua)) {
            $os = 'iOS (iPad)';
        } elseif (preg_match('/android/i', $ua)) {
            $os = 'Android';
        } elseif (preg_match('/windows nt/i', $ua)) {
            $os = 'Windows';
        } elseif (preg_match('/macintosh|mac os x/i', $ua)) {
            $os = 'macOS';
        } elseif (preg_match('/linux/i', $ua)) {
            $os = 'Linux';
        }

        $browser = 'Unknown Browser';
        if (preg_match('/edg/i', $ua)) {
            $browser = 'Edge';
        } elseif (preg_match('/chrome|crios/i', $ua) && ! preg_match('/edg/i', $ua)) {
            $browser = 'Chrome';
        } elseif (preg_match('/firefox|fxios/i', $ua)) {
            $browser = 'Firefox';
        } elseif (preg_match('/safari/i', $ua) && ! preg_match('/chrome|crios|android/i', $ua)) {
            $browser = 'Safari';
        } elseif (preg_match('/opera|opr/i', $ua)) {
            $browser = 'Opera';
        }

        return [
            'device' => $device,
            'browser' => $browser,
            'os' => $os,
        ];
    }
}
