<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use App\Services\CommunityService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Throwable;

class CommunityController extends Controller
{
    public function __construct(protected CommunityService $communityService) {}

    /**
     * GET /v1/communities — paginated communities for the auth user's wallet currency.
     */
    public function index(Request $request)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'filter' => ['sometimes', Rule::in(['all', 'joined', 'mine'])],
            'category_id' => ['sometimes', 'uuid', 'exists:community_categories,id'],
            'search' => ['sometimes', 'string', 'max:100'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:50'],
        ]);

        try {
            $filter = $validated['filter'] ?? 'all';
            $currency = userBaseCurrency($user->id);

            $communities = $this->communityService->list(
                $user,
                $validated,
                (int) ($validated['per_page'] ?? 10),
            );

            return response()->json([
                'success' => true,
                'message' => 'Communities',
                'currency' => in_array($filter, ['joined', 'mine'], true) ? null : $currency,
                'data' => $communities,
            ]);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to load communities', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load communities',
            ], 500);
        }
    }

    /**
     * GET /v1/communities/{id} — community details by ID.
     */
    public function show(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->showById($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community details',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load community',
            ], 500);
        }
    }

    /**
     * GET /v1/communities/s/{slug} — resolve a shared community link by slug.
     */
    public function showBySlug(Request $request, string $slug)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->showBySlug($user, $slug);

            return response()->json([
                'success' => true,
                'message' => 'Community details',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (Throwable $e) {
            Log::error('Failed to load community by slug', [
                'user_id' => $user->id,
                'slug' => $slug,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to load community',
            ], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/join — join by community ID.
     */
    public function join(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->communityService->joinById($user, $id, $validated);

            $message = match ($result['action']) {
                'joined' => 'Welcome to the community',
                'already_member' => 'You are already a member',
                'request_sent' => 'Your join request has been sent to the admin',
                'request_pending' => 'Your join request is already pending',
                default => 'Community membership updated',
            };

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to join community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to join community',
            ], 500);
        }
    }

    /**
     * POST /v1/communities/s/{slug}/join — join from a shared community link.
     */
    public function joinBySlug(Request $request, string $slug)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'invite_token' => ['sometimes', 'nullable', 'string', 'max:255'],
        ]);

        try {
            $result = $this->communityService->joinBySlug($user, $slug, $validated);

            $message = match ($result['action']) {
                'joined' => 'Welcome to the community',
                'already_member' => 'You are already a member',
                'request_sent' => 'Your join request has been sent to the admin',
                'request_pending' => 'Your join request is already pending',
                default => 'Community membership updated',
            };

            return response()->json([
                'success' => true,
                'message' => $message,
                'data' => $result,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Community not found',
            ], 404);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to join community by slug', [
                'user_id' => $user->id,
                'slug' => $slug,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to join community',
            ], 500);
        }
    }

    /**
     * GET /v1/communities/categories — list community categories for the create form.
     */
    public function categories(Request $request)
    {
        if (! resolveApiUser($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $categories = CommunityCategory::query()
            ->orderBy('name')
            ->get(['id', 'name']);

        return response()->json([
            'success' => true,
            'message' => 'Community categories',
            'data' => $categories,
        ]);
    }

    /**
     * POST /v1/communities — create a public, private, approval, or paid community.
     */
    public function store(Request $request)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $currency = userBaseCurrency($user->id);

        if (! $currency) {
            return response()->json([
                'success' => false,
                'message' => 'Set up your wallet currency before creating a community.',
            ], 422);
        }

        $billingTypes = $currency === 'NGN'
            ? ['one_off']
            : ['one_off', 'subscription'];

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['required', 'string', 'max:1000'],
            'community_categories_id' => ['required', 'uuid', 'exists:community_categories,id'],
            'type' => ['required', Rule::in(['public', 'private', 'paid', 'approval'])],
            'monthly_fee' => ['required_if:type,paid', 'nullable', 'numeric', 'min:'.communityMinimumPrice($currency)],
            'fee_payer' => ['required_if:type,paid', 'nullable', Rule::in(['creator', 'members'])],
            'billing_type' => ['required_if:type,paid', 'nullable', Rule::in($billingTypes)],
            'billing_interval' => [
                'required_if:billing_type,subscription',
                'nullable',
                Rule::in(array_keys(config('community.billing_intervals', []))),
            ],
        ], [
            'community_categories_id.required' => 'The category field is required.',
            'community_categories_id.exists' => 'The selected category is invalid.',
            'monthly_fee.required_if' => 'The price field is required for paid communities.',
            'monthly_fee.min' => 'The price must be at least '.communityMinimumPrice($currency).'.',
            'fee_payer.required_if' => 'The fee payer field is required for paid communities.',
            'billing_type.required_if' => 'The billing type field is required for paid communities.',
            'billing_type.in' => $currency === 'NGN'
                ? 'Subscription billing is not available for NGN communities. Use one_off.'
                : 'The selected billing type is invalid.',
            'billing_interval.required_if' => 'The billing interval field is required for subscription communities.',
        ]);

        if ($validated['type'] !== 'paid') {
            $validated['monthly_fee'] = null;
            $validated['fee_payer'] = null;
            $validated['billing_type'] = null;
            $validated['billing_interval'] = null;
        }

        if (($validated['type'] ?? null) === 'paid' && $currency === 'NGN') {
            $validated['billing_type'] = 'one_off';
            $validated['billing_interval'] = null;
        }

        try {
            $data = $this->communityService->create($user, $validated);

            return response()->json([
                'success' => true,
                'message' => 'Community created',
                'data' => $data,
            ], 201);
        } catch (InvalidArgumentException $e) {
            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 422);
        } catch (Throwable $e) {
            Log::error('Failed to create community', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to create community at this time',
            ], 500);
        }
    }

    /**
     * PUT/PATCH /v1/communities/{id} — update community settings (owner only).
     */
    public function update(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['sometimes', 'string', 'max:1000'],
            'community_categories_id' => ['sometimes', 'uuid', 'exists:community_categories,id'],
            'type' => ['sometimes', Rule::in(['public', 'private', 'paid', 'approval'])],
            'monthly_fee' => ['sometimes', 'nullable', 'numeric'],
            'fee_payer' => ['sometimes', 'nullable', Rule::in(['creator', 'members'])],
            'billing_type' => ['sometimes', 'nullable', Rule::in(['one_off', 'subscription'])],
            'billing_interval' => [
                'sometimes',
                'nullable',
                Rule::in(array_keys(config('community.billing_intervals', []))),
            ],
            'logo' => ['sometimes', 'nullable', 'image', 'max:4096'],
            'banner' => ['sometimes', 'nullable', 'image', 'max:6144'],
        ]);

        try {
            $logo = $request->file('logo');
            $banner = $request->file('banner');

            $data = $this->communityService->update($user, $id, $validated, $logo, $banner);

            return response()->json([
                'success' => true,
                'message' => 'Community settings updated',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (InvalidArgumentException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        } catch (Throwable $e) {
            Log::error('Failed to update community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to update community'], 500);
        }
    }

    /**
     * DELETE /v1/communities/{id} — delete a community (owner only).
     */
    public function destroy(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $result = $this->communityService->delete($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community deleted successfully',
                'data' => $result,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to delete community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to delete community'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/archive — archive a community (owner only).
     */
    public function archive(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->archive($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community archived successfully',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to archive community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to archive community'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/unarchive — restore an archived community (owner only).
     */
    public function unarchive(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->unarchive($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community unarchived successfully',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to unarchive community', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to unarchive community'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/logo — upload or update logo.
     */
    public function updateLogo(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'logo' => ['required', 'image', 'max:4096'],
        ]);

        try {
            $data = $this->communityService->updateLogo($user, $id, $request->file('logo'));

            return response()->json([
                'success' => true,
                'message' => 'Community logo updated',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to update community logo', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to update logo'], 500);
        }
    }

    /**
     * DELETE /v1/communities/{id}/logo — remove community logo.
     */
    public function removeLogo(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->removeLogo($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community logo removed',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to remove community logo', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to remove logo'], 500);
        }
    }

    /**
     * POST /v1/communities/{id}/banner — upload or update banner.
     */
    public function updateBanner(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $request->validate([
            'banner' => ['required', 'image', 'max:6144'],
        ]);

        try {
            $data = $this->communityService->updateBanner($user, $id, $request->file('banner'));

            return response()->json([
                'success' => true,
                'message' => 'Community banner updated',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to update community banner', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to update banner'], 500);
        }
    }

    /**
     * DELETE /v1/communities/{id}/banner — remove community banner.
     */
    public function removeBanner(Request $request, string $id)
    {
        $user = resolveApiUser($request);
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        try {
            $data = $this->communityService->removeBanner($user, $id);

            return response()->json([
                'success' => true,
                'message' => 'Community banner removed',
                'data' => $data,
            ]);
        } catch (ModelNotFoundException $e) {
            return response()->json(['success' => false, 'message' => 'Community not found'], 404);
        } catch (AuthorizationException $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 403);
        } catch (Throwable $e) {
            Log::error('Failed to remove community banner', [
                'user_id' => $user->id,
                'community_id' => $id,
                'message' => $e->getMessage(),
            ]);

            return response()->json(['success' => false, 'message' => 'Unable to remove banner'], 500);
        }
    }

    /**
     * POST /v1/communities/fee-preview — preview fee calculation for paid community.
     */
    public function feePreview(Request $request)
    {
        if (! resolveApiUser($request)) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'monthly_fee' => ['required', 'numeric', 'min:0'],
            'fee_payer' => ['sometimes', 'nullable', Rule::in(['creator', 'members'])],
            'billing_type' => ['sometimes', 'nullable', Rule::in(['one_off', 'subscription'])],
            'billing_interval' => [
                'sometimes',
                'nullable',
                Rule::in(array_keys(config('community.billing_intervals', []))),
            ],
        ]);

        $preview = $this->communityService->feePreview($validated);

        return response()->json([
            'success' => true,
            'message' => 'Fee preview',
            'data' => $preview,
        ]);
    }
}
