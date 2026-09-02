<?php

namespace App\Services;

use App\Models\Community;
use App\Models\CommunityInvite;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

class CommunityInviteService
{
    public function regenerateLinkInvite(Community $community, User $inviter): CommunityInvite
    {
        CommunityInvite::where('community_id', $community->id)
            ->where('type', 'link')
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        return CommunityInvite::create([
            'id' => (string) Str::uuid(),
            'community_id' => $community->id,
            'invited_by' => $inviter->id,
            'user_id' => null,
            'token' => Str::random(48),
            'type' => 'link',
            'status' => 'pending',
            'expires_at' => null,
        ]);
    }

    public function createDirectInvite(Community $community, User $inviter, User $invitee): CommunityInvite
    {
        CommunityInvite::where('community_id', $community->id)
            ->where('user_id', $invitee->id)
            ->where('type', 'direct')
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);

        return CommunityInvite::create([
            'id' => (string) Str::uuid(),
            'community_id' => $community->id,
            'invited_by' => $inviter->id,
            'user_id' => $invitee->id,
            'token' => Str::random(48),
            'type' => 'direct',
            'status' => 'pending',
            'expires_at' => now()->addDays(30),
        ]);
    }

    public function activeLinkInvite(Community $community): ?CommunityInvite
    {
        return CommunityInvite::where('community_id', $community->id)
            ->where('type', 'link')
            ->where('status', 'pending')
            ->latest()
            ->first();
    }

    public function pendingDirectInviteFor(Community $community, User $user): ?CommunityInvite
    {
        return CommunityInvite::where('community_id', $community->id)
            ->where('user_id', $user->id)
            ->where('type', 'direct')
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')->orWhere('expires_at', '>', now());
            })
            ->latest()
            ->first();
    }

    public function revokeLinkInvites(Community $community): void
    {
        CommunityInvite::where('community_id', $community->id)
            ->where('type', 'link')
            ->where('status', 'pending')
            ->update(['status' => 'revoked']);
    }

    public function acceptInvite(Community $community, User $user, string $token, CommunityMembershipService $membershipService): void
    {
        $invite = CommunityInvite::where('token', $token)
            ->where('community_id', $community->id)
            ->first();

        if (! $invite || ! $invite->isUsable()) {
            throw new InvalidArgumentException('This invite link is no longer valid.');
        }

        if ($invite->type === 'direct' && $invite->user_id !== $user->id) {
            throw new InvalidArgumentException('This invite was sent to another user.');
        }

        if ($membershipService->isBanned($community, $user->id)) {
            throw new InvalidArgumentException('You cannot rejoin this community.');
        }

        if ($community->members()->where('users.id', $user->id)->exists()) {
            return;
        }

        DB::transaction(function () use ($invite, $community, $user, $membershipService) {
            if (! $membershipService->attachMember($community, $user->id)) {
                throw new InvalidArgumentException('Unable to join this community.');
            }

            if ($invite->type === 'direct') {
                $invite->update([
                    'status' => 'accepted',
                    'accepted_at' => now(),
                ]);
            } else {
                $invite->increment('uses_count');
                $invite->update(['accepted_at' => now()]);
            }
        });
    }
}
