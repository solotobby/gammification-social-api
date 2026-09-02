<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityInvite;
use App\Models\CommunityJoinRequest;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CommunityMembershipFlowService
{
    public function __construct(
        protected CommunityMembershipService $membershipService,
        protected CommunityInviteService $inviteService,
        protected CommunityService $communityService,
    ) {}

    public function acceptInviteByToken(User $user, string $token): array
    {
        $invite = CommunityInvite::query()
            ->with(['community.category', 'community.user:id,username,name,avatar'])
            ->where('token', $token)
            ->first();

        if (! $invite || ! $invite->isUsable()) {
            throw new InvalidArgumentException('This invite link is no longer valid.');
        }

        $community = $invite->community;

        if (! $community || $community->isArchived()) {
            throw new InvalidArgumentException('This community is no longer available.');
        }

        if ($community->type !== 'private') {
            throw new InvalidArgumentException('This invite is not for a private community.');
        }

        if (! $community->isInCurrency(userBaseCurrency($user->id))) {
            throw new InvalidArgumentException('This community is not available in your currency.');
        }

        $this->inviteService->acceptInvite(
            $community,
            $user,
            $token,
            $this->membershipService,
        );

        $community->loadCount(['members', 'posts']);

        return [
            'action' => 'joined',
            'community' => $this->communityService->formatCommunityDetail($community, $user),
        ];
    }

    public function acceptDirectInvite(User $user, string $communityId): array
    {
        $community = $this->findCommunity($communityId);

        if ($community->type !== 'private') {
            throw new InvalidArgumentException('Direct invites are only used for private communities.');
        }

        if ($this->communityService->isMemberPublic($community, $user->id)) {
            return [
                'action' => 'already_member',
                'community' => $this->communityService->formatCommunityDetail($community, $user),
            ];
        }

        $invite = $this->inviteService->pendingDirectInviteFor($community, $user);

        if (! $invite) {
            throw new InvalidArgumentException('You do not have a pending invitation for this community.');
        }

        $this->inviteService->acceptInvite(
            $community,
            $user,
            $invite->token,
            $this->membershipService,
        );

        $community->loadCount(['members', 'posts']);

        return [
            'action' => 'joined',
            'community' => $this->communityService->formatCommunityDetail($community, $user),
        ];
    }

    public function leave(User $user, string $communityId): array
    {
        $community = $this->findCommunity($communityId);
        $userId = authUserId($user) ?? (string) $user->id;

        if ($this->communityService->isOwnerPublic($community, $userId)) {
            throw new InvalidArgumentException('Owners cannot leave — transfer ownership or delete the community.');
        }

        if (! $this->communityService->isMemberPublic($community, $userId)) {
            throw new InvalidArgumentException($this->notMemberMessage($community, $user));
        }

        if (! $this->membershipService->leave($community, $user)) {
            throw new InvalidArgumentException('Unable to leave this community.');
        }

        $community->loadCount(['members', 'posts']);

        return [
            'action' => 'left',
            'community' => $this->communityService->formatCommunityDetail($community, $user),
        ];
    }

    public function listJoinRequests(User $user, string $communityId, ?string $status = 'pending'): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanManage($community, $user);

        if ($community->type !== 'approval') {
            throw new InvalidArgumentException('Join requests are only used for approval communities.');
        }

        $query = CommunityJoinRequest::query()
            ->where('community_id', $community->id)
            ->with('user:id,username,name,avatar')
            ->latest();

        if ($status) {
            $query->where('status', $status);
        }

        return $query->get()->map(fn (CommunityJoinRequest $request) => $this->formatJoinRequest($request))->all();
    }

    public function approveJoinRequest(User $user, string $communityId, string $requestId): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanManage($community, $user);

        if ($community->type !== 'approval') {
            throw new InvalidArgumentException('Join requests are only used for approval communities.');
        }

        $joinRequest = CommunityJoinRequest::query()
            ->where('community_id', $community->id)
            ->find($requestId);

        if (! $joinRequest || $joinRequest->status !== 'pending') {
            throw new ModelNotFoundException('Join request not found.');
        }

        DB::transaction(function () use ($community, $joinRequest, $user) {
            if (! $this->membershipService->attachMember($community, $joinRequest->user_id)) {
                throw new InvalidArgumentException('Could not approve — user may be banned from this community.');
            }

            $joinRequest->update([
                'status' => 'approved',
                'reviewed_by' => $user->id,
                'reviewed_at' => now(),
            ]);
        });

        $joinRequest->load('user:id,username,name,avatar');

        return [
            'action' => 'approved',
            'join_request' => $this->formatJoinRequest($joinRequest),
        ];
    }

    public function denyJoinRequest(User $user, string $communityId, string $requestId, ?string $reason = null): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanManage($community, $user);

        if ($community->type !== 'approval') {
            throw new InvalidArgumentException('Join requests are only used for approval communities.');
        }

        $joinRequest = CommunityJoinRequest::query()
            ->where('community_id', $community->id)
            ->find($requestId);

        if (! $joinRequest || $joinRequest->status !== 'pending') {
            throw new ModelNotFoundException('Join request not found.');
        }

        $reason = trim((string) $reason);

        $joinRequest->update([
            'status' => 'denied',
            'reason' => $reason !== '' ? $reason : null,
            'reviewed_by' => $user->id,
            'reviewed_at' => now(),
        ]);

        $joinRequest->load('user:id,username,name,avatar');

        return [
            'action' => 'denied',
            'join_request' => $this->formatJoinRequest($joinRequest),
        ];
    }

    public function listInvites(User $user, string $communityId): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanInvite($community, $user);

        if ($community->type !== 'private') {
            throw new InvalidArgumentException('Invites are only used for private communities.');
        }

        $linkInvite = $this->inviteService->activeLinkInvite($community);

        $directInvites = CommunityInvite::query()
            ->where('community_id', $community->id)
            ->where('type', 'direct')
            ->where('status', 'pending')
            ->with('user:id,username,name,avatar')
            ->latest()
            ->get();

        return [
            'link_invite' => $linkInvite ? $this->formatInvite($linkInvite) : null,
            'direct_invites' => $directInvites->map(fn (CommunityInvite $invite) => $this->formatInvite($invite))->all(),
        ];
    }

    public function inviteDirect(User $user, string $communityId, string $identifier): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanInvite($community, $user);

        if ($community->type !== 'private') {
            throw new InvalidArgumentException('Direct invites are only used for private communities.');
        }

        $identifier = trim($identifier);

        $invitee = User::query()
            ->where('status', 'ACTIVE')
            ->where(function ($q) use ($identifier) {
                $q->where('username', $identifier)
                    ->orWhere('email', $identifier);
            })
            ->first();

        if (! $invitee) {
            throw new InvalidArgumentException('No active user found with that username or email.');
        }

        if ($invitee->id === $user->id) {
            throw new InvalidArgumentException('You cannot invite yourself.');
        }

        if ($community->members()->where('users.id', $invitee->id)->exists()) {
            throw new InvalidArgumentException('That user is already a member.');
        }

        $invite = $this->inviteService->createDirectInvite($community, $user, $invitee);

        return [
            'action' => 'invited',
            'invite' => $this->formatInvite($invite->load('user:id,username,name,avatar')),
        ];
    }

    public function generateLinkInvite(User $user, string $communityId): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanInvite($community, $user);

        if ($community->type !== 'private') {
            throw new InvalidArgumentException('Link invites are only used for private communities.');
        }

        $invite = $this->inviteService->regenerateLinkInvite($community, $user);

        return [
            'action' => 'link_generated',
            'invite' => $this->formatInvite($invite),
        ];
    }

    public function revokeLinkInvite(User $user, string $communityId): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanInvite($community, $user);

        $this->inviteService->revokeLinkInvites($community);

        return ['action' => 'link_revoked'];
    }

    public function revokeDirectInvite(User $user, string $communityId, string $inviteId): array
    {
        $community = $this->findCommunity($communityId);
        $this->assertCanInvite($community, $user);

        $updated = CommunityInvite::query()
            ->where('community_id', $community->id)
            ->where('id', $inviteId)
            ->where('type', 'direct')
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        if (! $updated) {
            throw new ModelNotFoundException('Invitation not found.');
        }

        return ['action' => 'invite_revoked'];
    }

    private function findCommunity(string $communityId): Community
    {
        $community = Community::query()
            ->with(['category', 'user:id,username,name,avatar'])
            ->withCount(['members', 'posts'])
            ->find($communityId);

        if (! $community) {
            throw new ModelNotFoundException('Community not found.');
        }

        return $community;
    }

    private function assertCanManage(Community $community, User $user): void
    {
        if (! $this->communityService->canManagePublic($community, $user->id)) {
            throw new AuthorizationException('Only the community owner or an admin can manage members.');
        }
    }

    private function assertCanInvite(Community $community, User $user): void
    {
        if (! $this->communityService->canManagePublic($community, $user->id)) {
            throw new AuthorizationException('Only the community owner or an admin can manage invitations.');
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function formatJoinRequest(CommunityJoinRequest $request): array
    {
        return [
            'id' => $request->id,
            'status' => $request->status,
            'reason' => $request->reason,
            'user' => $request->user ? [
                'id' => $request->user->id,
                'username' => $request->user->username,
                'name' => $request->user->name,
                'avatar' => $request->user->avatar,
            ] : null,
            'reviewed_at' => $request->reviewed_at?->toIso8601String(),
            'created_at' => $request->created_at?->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function formatInvite(CommunityInvite $invite): array
    {
        return [
            'id' => $invite->id,
            'type' => $invite->type,
            'token' => $invite->token,
            'status' => $invite->status,
            'uses_count' => (int) ($invite->uses_count ?? 0),
            'expires_at' => $invite->expires_at?->toIso8601String(),
            'user' => $invite->user ? [
                'id' => $invite->user->id,
                'username' => $invite->user->username,
                'name' => $invite->user->name,
                'avatar' => $invite->user->avatar,
            ] : null,
        ];
    }

    private function notMemberMessage(Community $community, User $user): string
    {
        $message = 'You are not a member of this community.';

        $ownerUsername = $community->user?->username;
        if ($ownerUsername && authUserId($user) !== (string) $community->user_id) {
            $message .= " It was created by @{$ownerUsername}.";
        }

        $message .= ' Confirm you are signed in as the correct account via GET /v1/user/me.';

        return $message;
    }
}
