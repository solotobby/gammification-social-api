<?php

namespace App\Http\Controllers\V1\Community;

use App\Http\Controllers\Controller;
use App\Models\CommunityCategory;
use App\Services\CommunityService;
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
}
