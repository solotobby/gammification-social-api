<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Services\CommunityMembershipFlowService;
use App\Services\CommunityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

class CommunityMembershipController extends Controller
{
    public function __construct(
        protected CommunityMembershipFlowService $membershipFlowService,
        protected CommunityService $communityService,
    ) {}

    /**
     * POST /v1/communities/invites/{token}/accept — accept a private invite by token.
     */
    public function acceptInviteByToken(Request $request, string $token)
    {
        return $this->respond($request, function () use ($request, $token) {
            $result = $this->membershipFlowService->acceptInviteByToken(resolveApiUser($request), $token);

            return [
                'message' => 'Welcome to the community',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/join/accept-invite — accept a pending direct invite.
     */
    public function acceptDirectInvite(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $result = $this->membershipFlowService->acceptDirectInvite(resolveApiUser($request), $id);

            return [
                'message' => $result['action'] === 'already_member'
                    ? 'You are already a member'
                    : 'Welcome to the community',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/leave — leave a community.
     */
    public function leave(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $result = $this->membershipFlowService->leave(resolveApiUser($request), $id);

            return [
                'message' => 'You have left the community',
                'data' => $result,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/join-requests — list join requests (approval communities).
     */
    public function joinRequests(Request $request, string $id)
    {
        $validated = $request->validate([
            'status' => ['sometimes', 'nullable', 'in:pending,approved,denied'],
        ]);

        return $this->respond($request, function () use ($request, $id, $validated) {
            $requests = $this->membershipFlowService->listJoinRequests(
                resolveApiUser($request),
                $id,
                $validated['status'] ?? 'pending',
            );

            return [
                'message' => 'Join requests',
                'data' => $requests,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/join-requests/{requestId}/approve
     */
    public function approveJoinRequest(Request $request, string $id, string $requestId)
    {
        return $this->respond($request, function () use ($request, $id, $requestId) {
            $result = $this->membershipFlowService->approveJoinRequest(
                resolveApiUser($request),
                $id,
                $requestId,
            );

            return [
                'message' => 'Join request approved',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/join-requests/{requestId}/deny
     */
    public function denyJoinRequest(Request $request, string $id, string $requestId)
    {
        $validated = $request->validate([
            'reason' => ['sometimes', 'nullable', 'string', 'max:500'],
        ]);

        return $this->respond($request, function () use ($request, $id, $requestId, $validated) {
            $result = $this->membershipFlowService->denyJoinRequest(
                resolveApiUser($request),
                $id,
                $requestId,
                $validated['reason'] ?? null,
            );

            return [
                'message' => 'Join request denied',
                'data' => $result,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/invites — list private community invites.
     */
    public function invites(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $result = $this->membershipFlowService->listInvites(resolveApiUser($request), $id);

            return [
                'message' => 'Community invites',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/invites/direct — invite a user by username or email.
     */
    public function inviteDirect(Request $request, string $id)
    {
        $validated = $request->validate([
            'identifier' => ['required', 'string', 'max:255'],
        ]);

        return $this->respond($request, function () use ($request, $id, $validated) {
            $result = $this->membershipFlowService->inviteDirect(
                resolveApiUser($request),
                $id,
                $validated['identifier'],
            );

            return [
                'message' => 'Invitation sent',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/invites/link — generate a shareable invite link.
     */
    public function generateLinkInvite(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $result = $this->membershipFlowService->generateLinkInvite(resolveApiUser($request), $id);

            return [
                'message' => 'Invite link generated',
                'data' => $result,
            ];
        });
    }

    /**
     * DELETE /v1/communities/{id}/invites/link — revoke the active link invite.
     */
    public function revokeLinkInvite(Request $request, string $id)
    {
        return $this->respond($request, function () use ($request, $id) {
            $result = $this->membershipFlowService->revokeLinkInvite(resolveApiUser($request), $id);

            return [
                'message' => 'Invite link revoked',
                'data' => $result,
            ];
        });
    }

    /**
     * DELETE /v1/communities/{id}/invites/{inviteId} — revoke a direct invite.
     */
    public function revokeDirectInvite(Request $request, string $id, string $inviteId)
    {
        return $this->respond($request, function () use ($request, $id, $inviteId) {
            $result = $this->membershipFlowService->revokeDirectInvite(
                resolveApiUser($request),
                $id,
                $inviteId,
            );

            return [
                'message' => 'Invitation revoked',
                'data' => $result,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/members — list community members.
     */
    public function members(Request $request, string $id)
    {
        $validated = $request->validate([
            'search' => ['sometimes', 'nullable', 'string', 'max:100'],
            'role' => ['sometimes', 'nullable', 'in:owner,admin,member'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->respond($request, function () use ($request, $id, $validated) {
            $members = $this->membershipFlowService->listMembers(
                resolveApiUser($request),
                $id,
                $validated['search'] ?? null,
                $validated['role'] ?? null,
                (int) ($validated['per_page'] ?? 15),
            );

            return [
                'message' => 'Community members',
                'data' => $members,
            ];
        });
    }

    /**
     * GET /v1/communities/{id}/members/banned — list banned members (owner/admin only).
     */
    public function bannedMembers(Request $request, string $id)
    {
        $validated = $request->validate([
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        return $this->respond($request, function () use ($request, $id, $validated) {
            $banned = $this->membershipFlowService->listBannedMembers(
                resolveApiUser($request),
                $id,
                (int) ($validated['per_page'] ?? 15),
            );

            return [
                'message' => 'Banned members',
                'data' => $banned,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/members/{userId}/promote — promote member to admin (owner only).
     */
    public function promoteToAdmin(Request $request, string $id, string $userId)
    {
        return $this->respond($request, function () use ($request, $id, $userId) {
            $result = $this->membershipFlowService->promoteToAdmin(
                resolveApiUser($request),
                $id,
                $userId,
            );

            return [
                'message' => 'Member promoted to admin',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/members/{userId}/demote — demote admin to member (owner only).
     */
    public function demoteToMember(Request $request, string $id, string $userId)
    {
        return $this->respond($request, function () use ($request, $id, $userId) {
            $result = $this->membershipFlowService->demoteToMember(
                resolveApiUser($request),
                $id,
                $userId,
            );

            return [
                'message' => 'Admin demoted to member',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/members/{userId}/ban — ban member (owner/admin).
     */
    public function banMember(Request $request, string $id, string $userId)
    {
        return $this->respond($request, function () use ($request, $id, $userId) {
            $result = $this->membershipFlowService->banMember(
                resolveApiUser($request),
                $id,
                $userId,
            );

            return [
                'message' => 'Member has been banned',
                'data' => $result,
            ];
        });
    }

    /**
     * POST /v1/communities/{id}/members/{userId}/unban — unban member (owner/admin).
     */
    public function unbanMember(Request $request, string $id, string $userId)
    {
        return $this->respond($request, function () use ($request, $id, $userId) {
            $result = $this->membershipFlowService->unbanMember(
                resolveApiUser($request),
                $id,
                $userId,
            );

            return [
                'message' => 'Member unbanned successfully',
                'data' => $result,
            ];
        });
    }

    /**
     * DELETE /v1/communities/{id}/members/{userId} — remove/kick member (owner/admin).
     */
    public function removeMember(Request $request, string $id, string $userId)
    {
        return $this->respond($request, function () use ($request, $id, $userId) {
            $result = $this->membershipFlowService->removeMember(
                resolveApiUser($request),
                $id,
                $userId,
            );

            return [
                'message' => 'Member removed from community',
                'data' => $result,
            ];
        });
    }

    /**
     * @param  callable(): array{message: string, data: mixed}  $callback
     */
    private function respond(Request $request, callable $callback)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $payload = $callback();

            return response()->json([
                'success' => true,
                'message' => $payload['message'],
                'data' => $payload['data'],
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Resource not found',
            ], 404);
        } catch (AuthorizationException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Community membership action failed', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to process request',
            ], 500);
        }
    }
}
