<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityInvite;
use App\Models\CommunityJoinRequest;
use App\Models\CommunitySubscription;
use App\Models\User;
use App\Support\CommunityFeeCalculator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CommunityService
{
    public function __construct(
        protected CommunityInviteService $communityInviteService,
        protected CommunityMembershipService $communityMembershipService,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     */
    public function list(User $user, array $filters, int $perPage = 10): LengthAwarePaginator
    {
        $filter = $filters['filter'] ?? 'all';
        $currency = userBaseCurrency($user->id);

        if (! in_array($filter, ['joined', 'mine'], true) && ! $currency) {
            throw new InvalidArgumentException('Set up your wallet currency to browse communities.');
        }

        $query = $this->buildListQuery($user, $filters, $currency);
        $paginator = $query->latest()->paginate($perPage);

        $paginator->getCollection()->transform(
            fn (Community $community) => $this->formatCommunityListItem($community),
        );

        return $paginator;
    }

    public function showById(User $user, string $id): array
    {
        $community = $this->communityDetailQuery()->find($id);

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        $this->assertViewable($community, $user);

        return $this->formatCommunityDetail($community, $user);
    }

    public function showBySlug(User $user, string $slug): array
    {
        $community = $this->communityDetailQuery()
            ->where('slug', $slug)
            ->first();

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        $this->assertViewable($community, $user);

        return $this->formatCommunityDetail($community, $user);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function joinById(User $user, string $communityId, array $payload = []): array
    {
        $community = $this->communityDetailQuery()
            ->whereNull('archived_at')
            ->find($communityId);

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        return $this->joinCommunity($user, $community, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function joinBySlug(User $user, string $slug, array $payload = []): array
    {
        $community = $this->communityDetailQuery()
            ->where('slug', $slug)
            ->whereNull('archived_at')
            ->first();

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        return $this->joinCommunity($user, $community, $payload);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function joinCommunity(User $user, Community $community, array $payload = []): array
    {
        if (! $community->isInCurrency(userBaseCurrency($user->id))) {
            throw new InvalidArgumentException('This community is not available in your currency.');
        }

        if ($this->isMember($community, $user->id)) {
            return [
                'action' => 'already_member',
                'community' => $this->formatCommunityDetail($community, $user),
            ];
        }

        if ($this->communityMembershipService->isBanned($community, $user->id)) {
            throw new InvalidArgumentException('You cannot rejoin this community.');
        }

        $action = match ($community->type) {
            'public' => $this->joinPublic($community, $user),
            'approval' => $this->requestToJoin($community, $user),
            'private' => $this->joinPrivate($community, $user, $payload['invite_token'] ?? null),
            'paid' => throw new InvalidArgumentException('Payment is required to join this community.'),
            default => throw new InvalidArgumentException('Unable to join this community.'),
        };

        $community->refresh();
        $community->load(['category', 'user:id,username,name,avatar']);
        $community->loadCount(['members', 'posts']);

        return [
            'action' => $action,
            'community' => $this->formatCommunityDetail($community, $user),
        ];
    }

    private function communityDetailQuery(): Builder
    {
        return Community::query()
            ->with(['category', 'user:id,username,name,avatar'])
            ->withCount(['members', 'posts']);
    }

    /**
     * @param  array<string, mixed>  $validated
     */
    public function create(User $user, array $validated): array
    {
        $currency = userBaseCurrency($user->id);

        if (! $currency) {
            throw new InvalidArgumentException('Set up your wallet currency before creating a community.');
        }

        $isPaid = $validated['type'] === 'paid';
        $billingType = $isPaid ? ($validated['billing_type'] ?? null) : null;
        $billingInterval = null;

        if ($isPaid && $currency === 'NGN') {
            $billingType = 'one_off';
        }

        if ($isPaid && $billingType === 'subscription') {
            $billingInterval = $validated['billing_interval'] ?? null;
        }

        $platformFeePercent = (int) config('community.platform_fee_percent', 10);

        $invite = null;

        $community = DB::transaction(function () use (
            $user,
            $validated,
            $currency,
            $isPaid,
            $billingType,
            $billingInterval,
            $platformFeePercent,
            &$invite,
        ) {
            $community = Community::create([
                'name' => $validated['name'],
                'slug' => $this->uniqueSlug($validated['name']),
                'description' => $validated['description'],
                'community_categories_id' => $validated['community_categories_id'],
                'type' => $validated['type'],
                'currency' => $currency,
                'monthly_fee' => $isPaid ? $validated['monthly_fee'] : 0,
                'fee_payer' => $isPaid ? $validated['fee_payer'] : 'creator',
                'billing_type' => $isPaid ? $billingType : null,
                'billing_interval' => $isPaid && $billingType === 'subscription' ? $billingInterval : null,
                'platform_fee_percent' => $isPaid ? $platformFeePercent : null,
                'user_id' => $user->id,
            ]);

            if (! $this->communityMembershipService->attachMember($community, $user->id, 'owner')) {
                throw new InvalidArgumentException('Unable to add you as the community owner.');
            }

            if ($validated['type'] === 'private') {
                $invite = $this->communityInviteService->regenerateLinkInvite($community, $user);
            }

            return $community;
        });

        $community->load(['category', 'user:id,username,name,avatar']);
        $community->loadCount('members');

        return $this->formatCommunityDetail($community, $user, $invite);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    private function buildListQuery(User $user, array $filters, ?string $currency): Builder
    {
        $filter = $filters['filter'] ?? 'all';
        $search = trim((string) ($filters['search'] ?? ''));
        $categoryId = $filters['category_id'] ?? null;

        $query = Community::query()
            ->with(['category', 'user:id,username,name,avatar'])
            ->withCount('members')
            ->whereNull('archived_at');

        if ($filter === 'joined') {
            $query->where(function ($q) use ($user) {
                $q->whereHas('members', fn ($m) => $m->where('users.id', $user->id))
                    ->orWhere('user_id', $user->id);
            });
        } elseif ($filter === 'mine') {
            $query->where('user_id', $user->id);
        } elseif ($categoryId) {
            $query->where('community_categories_id', $categoryId);
        }

        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }

        if (! in_array($filter, ['joined', 'mine'], true)) {
            $query->forUserCurrency($currency);

            $query->where(function ($q) use ($user) {
                $q->where('type', '!=', 'private')
                    ->orWhere('user_id', $user->id)
                    ->orWhereHas('members', fn ($m) => $m->where('users.id', $user->id))
                    ->orWhereHas('invites', function ($inv) use ($user) {
                        $inv->where('user_id', $user->id)
                            ->where('type', 'direct')
                            ->where('status', 'pending')
                            ->where(function ($exp) {
                                $exp->whereNull('expires_at')->orWhere('expires_at', '>', now());
                            });
                    });
            });
        }

        return $query;
    }

    private function assertViewable(Community $community, User $user): void
    {
        if ($community->isArchived() && ! $this->isOwnerOrAdmin($community, $user->id)) {
            throw new ModelNotFoundException('Community not found.');
        }

        if (
            ! $community->isInCurrency(userBaseCurrency($user->id))
            && ! $this->isOwner($community, $user->id)
            && ! $this->isMember($community, $user->id)
        ) {
            throw new ModelNotFoundException('Community not found.');
        }
    }

    private function joinPublic(Community $community, User $user): string
    {
        if (! $this->communityMembershipService->attachMember($community, $user->id)) {
            throw new InvalidArgumentException('Unable to join this community.');
        }

        return 'joined';
    }

    private function joinPrivate(Community $community, User $user, ?string $inviteToken): string
    {
        if ($inviteToken) {
            $this->communityInviteService->acceptInvite(
                $community,
                $user,
                $inviteToken,
                $this->communityMembershipService,
            );

            return 'joined';
        }

        $invite = $this->communityInviteService->pendingDirectInviteFor($community, $user);

        if ($invite) {
            $this->communityInviteService->acceptInvite(
                $community,
                $user,
                $invite->token,
                $this->communityMembershipService,
            );

            return 'joined';
        }

        throw new InvalidArgumentException('An invite token is required to join this private community.');
    }

    private function requestToJoin(Community $community, User $user): string
    {
        if ($this->hasPendingJoinRequest($community, $user->id)) {
            return 'request_pending';
        }

        $joinRequest = CommunityJoinRequest::firstOrNew([
            'community_id' => $community->id,
            'user_id' => $user->id,
            'status' => 'pending',
        ]);

        if ($joinRequest->exists) {
            return 'request_pending';
        }

        $joinRequest->id = (string) Str::uuid();
        $joinRequest->save();

        return 'request_sent';
    }

    public function formatCommunityListItem(Community $community): array
    {
        $data = [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'description' => $community->description,
            'type' => $community->type,
            'currency' => $community->currency,
            'image' => $community->image,
            'banner' => $community->banner,
            'members_count' => (int) ($community->members_count ?? 0),
            'category' => $community->category ? [
                'id' => $community->category->id,
                'name' => $community->category->name,
            ] : null,
            'owner' => $community->user ? [
                'id' => $community->user->id,
                'username' => $community->user->username,
                'name' => $community->user->name,
                'avatar' => $community->user->avatar,
            ] : null,
            'created_at' => $community->created_at?->toIso8601String(),
        ];

        if ($community->type === 'paid') {
            $data['pricing'] = $this->pricingPayload($community);
        }

        return $data;
    }

    public function formatCommunitySummary(Community $community, User $user): array
    {
        $data = [
            'id' => $community->id,
            'name' => $community->name,
            'slug' => $community->slug,
            'description' => $community->description,
            'type' => $community->type,
            'currency' => $community->currency,
            'image' => $community->image,
            'banner' => $community->banner,
            'members_count' => (int) ($community->members_count ?? 0),
            'category' => $community->category ? [
                'id' => $community->category->id,
                'name' => $community->category->name,
            ] : null,
            'owner' => $community->user ? [
                'id' => $community->user->id,
                'username' => $community->user->username,
                'name' => $community->user->name,
                'avatar' => $community->user->avatar,
            ] : null,
            'membership' => $this->membershipContext($community, $user),
            'created_at' => $community->created_at?->toIso8601String(),
        ];

        if ($community->type === 'paid') {
            $data['pricing'] = $this->pricingPayload($community);
        }

        return $data;
    }

    public function formatCommunityDetail(
        Community $community,
        User $user,
        ?CommunityInvite $invite = null,
    ): array {
        $data = $this->formatCommunitySummary($community, $user);

        $data['posts_count'] = (int) ($community->posts_count ?? 0);
        $data['share_slug'] = $community->slug;
        $data['share_url'] = rtrim((string) config('app.url'), '/').'/c/'.$community->slug;
        $data['access'] = [
            'can_view_feed' => $this->canViewFeed($community, $user),
            'can_view_members' => $this->canViewMembers($community, $user),
            'feed_gate_message' => $this->feedGateMessage($community, $user),
        ];

        if ($invite) {
            $data['invite'] = [
                'token' => $invite->token,
                'type' => $invite->type,
            ];
        }

        return $data;
    }

    /**
     * @return array<string, mixed>
     */
    private function membershipContext(Community $community, User $user): array
    {
        $isMember = $this->isMember($community, $user->id);

        return [
            'is_owner' => $this->isOwner($community, $user->id),
            'is_admin' => $this->isAdmin($community, $user->id),
            'is_member' => $isMember,
            'role' => $this->memberRole($community, $user->id),
            'pending_join_request' => $this->hasPendingJoinRequest($community, $user->id),
            'pending_invite' => $this->hasPendingDirectInvite($community, $user->id),
            'subscription_status' => $this->subscriptionStatus($community, $user->id),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function pricingPayload(Community $community): ?array
    {
        if ($community->type !== 'paid') {
            return null;
        }

        $breakdown = CommunityFeeCalculator::breakdown(
            (float) $community->monthly_fee,
            (int) ($community->platform_fee_percent ?? 0),
            (string) $community->fee_payer,
        );

        return [
            'list_price' => (float) $community->monthly_fee,
            'fee_payer' => $community->fee_payer,
            'billing_type' => $community->billing_type,
            'billing_interval' => $community->billing_interval,
            'billing_label' => $community->billing_label,
            'platform_fee_percent' => (int) $community->platform_fee_percent,
            'member_charge' => $breakdown['memberCharge'],
            'platform_fee' => $breakdown['platformCut'],
            'creator_payout' => $breakdown['creatorPayout'],
        ];
    }

    public function isOwnerPublic(Community $community, string $userId): bool
    {
        return $this->isOwner($community, $userId);
    }

    public function isMemberPublic(Community $community, string $userId): bool
    {
        return $this->isMember($community, $userId);
    }

    public function canManagePublic(Community $community, string $userId): bool
    {
        return $this->isOwnerOrAdmin($community, $userId);
    }

    public function canViewFeedPublic(Community $community, User $user): bool
    {
        return $this->canViewFeed($community, $user);
    }

    public function assertCanViewFeed(Community $community, User $user): void
    {
        if (! $this->canViewFeed($community, $user)) {
            throw new InvalidArgumentException('You do not have access to this community feed.');
        }
    }

    public function assertCanInteract(Community $community, User $user): void
    {
        if (! $this->isMember($community, $user->id)) {
            throw new InvalidArgumentException('Only members can interact with posts in this community.');
        }
    }

    private function isOwner(Community $community, string $userId): bool
    {
        $userId = (string) $userId;

        if ((string) $community->user_id === $userId) {
            return true;
        }

        return DB::table('community_users')
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('role', 'owner')
            ->where('status', 'active')
            ->exists();
    }

    private function isAdmin(Community $community, string $userId): bool
    {
        return $community->members()
            ->where('users.id', $userId)
            ->wherePivot('role', 'admin')
            ->exists();
    }

    private function isOwnerOrAdmin(Community $community, string $userId): bool
    {
        return $this->isOwner($community, $userId) || $this->isAdmin($community, $userId);
    }

    private function isMember(Community $community, string $userId): bool
    {
        $userId = (string) $userId;

        if ($this->isOwner($community, $userId)) {
            return true;
        }

        return DB::table('community_users')
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->exists();
    }

    private function memberRole(Community $community, string $userId): ?string
    {
        if (! $this->isMember($community, $userId)) {
            return null;
        }

        if ($this->isOwner($community, $userId)) {
            return 'owner';
        }

        return DB::table('community_users')
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('status', 'active')
            ->value('role') ?: 'member';
    }

    private function hasPendingJoinRequest(Community $community, string $userId): bool
    {
        return CommunityJoinRequest::query()
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('status', 'pending')
            ->exists();
    }

    private function hasPendingDirectInvite(Community $community, string $userId): bool
    {
        return CommunityInvite::query()
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('type', 'direct')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->exists();
    }

    private function subscriptionStatus(Community $community, string $userId): ?string
    {
        if ($community->type !== 'paid') {
            return null;
        }

        return CommunitySubscription::query()
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->latest()
            ->value('status');
    }

    private function canViewFeed(Community $community, User $user): bool
    {
        if ($this->isOwnerOrAdmin($community, $user->id)) {
            return true;
        }

        return match ($community->type) {
            'private', 'paid', 'approval' => $this->isMember($community, $user->id),
            default => true,
        };
    }

    private function canViewMembers(Community $community, User $user): bool
    {
        return $community->type === 'public'
            || $this->isOwner($community, $user->id)
            || $this->isAdmin($community, $user->id)
            || $this->isMember($community, $user->id);
    }

    private function feedGateMessage(Community $community, User $user): ?string
    {
        if ($this->canViewFeed($community, $user)) {
            return null;
        }

        return match ($community->type) {
            'paid' => 'Subscribe or pay to join before you can view posts in this community.',
            'approval' => 'Your join request must be approved before you can view posts here.',
            'private' => $this->hasPendingDirectInvite($community, $user->id)
                ? 'You have been invited — accept the invitation to see posts and participate.'
                : 'Only invited members can view the feed. Ask the admin for an invite link.',
            default => 'Join this community to view the feed.',
        };
    }

    private function uniqueSlug(string $name): string
    {
        $base = Str::slug($name);
        $slug = $base ?: Str::random(8);
        $suffix = 1;

        while (Community::where('slug', $slug)->exists()) {
            $slug = "{$base}-{$suffix}";
            $suffix++;
        }

        return $slug;
    }
}
