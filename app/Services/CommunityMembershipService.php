<?php

namespace App\Services;

use App\Models\Community;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CommunityMembershipService
{
    /**
     * Attach or re-activate a member. Returns false when the user is banned
     * or the community is archived (non-owner joins blocked).
     */
    public function attachMember(Community $community, string $userId, string $role = 'member'): bool
    {
        $userId = (string) $userId;

        if ($community->isArchived() && (string) $community->user_id !== $userId) {
            return false;
        }

        return DB::transaction(function () use ($community, $userId, $role) {
            $existing = DB::table('community_users')
                ->where('community_id', $community->id)
                ->where('user_id', $userId)
                ->lockForUpdate()
                ->first();

            if ($existing && $existing->status === 'banned') {
                return false;
            }

            if ($existing) {
                DB::table('community_users')
                    ->where('id', $existing->id)
                    ->update([
                        'role' => $role,
                        'status' => 'active',
                        'updated_at' => now(),
                    ]);
            } else {
                DB::table('community_users')->insert([
                    'id' => (string) Str::uuid(),
                    'community_id' => $community->id,
                    'user_id' => $userId,
                    'role' => $role,
                    'status' => 'active',
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            return true;
        });
    }

    public function leave(Community $community, User $user): bool
    {
        $userId = authUserId($user) ?? (string) $user->id;

        if ($this->isCommunityOwner($community, $userId)) {
            return false;
        }

        return DB::transaction(function () use ($community, $userId) {
            return (bool) DB::table('community_users')
                ->where('community_id', $community->id)
                ->where('user_id', $userId)
                ->where('status', 'active')
                ->delete();
        });
    }

    public function isCommunityOwner(Community $community, string $userId): bool
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

    public function isBanned(Community $community, string $userId): bool
    {
        return DB::table('community_users')
            ->where('community_id', $community->id)
            ->where('user_id', $userId)
            ->where('status', 'banned')
            ->exists();
    }
}
