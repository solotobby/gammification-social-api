<?php

namespace App\Http\Controllers\V1\User;

use App\Http\Controllers\Controller;
use App\Models\Social;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Throwable;

class SocialController extends Controller
{
    public function show(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $social = Social::where('user_id', $user->id)->first();

        return response()->json([
            'success' => true,
            'message' => 'Social links',
            'data' => $this->present($social),
        ]);
    }

    public function update(Request $request)
    {
        $user = $request->user();
        if (! $user) {
            return response()->json(['success' => false, 'message' => 'Unauthenticated'], 401);
        }

        $validated = $request->validate([
            'facebook' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],
            'instagram' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],
            'x' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],
            'linkedin' => ['sometimes', 'nullable', 'string', 'max:255', 'url'],
            'pinterest' => ['sometimes', 'nullable', 'string', 'max:100', 'regex:/^[A-Za-z0-9._-]+$/'],
        ], [
            'pinterest.regex' => 'Pinterest must be a username, not a URL.',
        ]);

        try {
            $map = [
                'facebook' => 'facebook',
                'instagram' => 'instagram',
                'x' => 'twitter',
                'linkedin' => 'linkedin',
                'pinterest' => 'pinterest',
            ];

            $payload = [];
            foreach ($map as $input => $column) {
                if (! array_key_exists($input, $validated)) {
                    continue;
                }
                $payload[$column] = $this->nullableString($validated[$input] ?? null);
            }

            $social = Social::updateOrCreate(
                ['user_id' => $user->id],
                $payload
            );

            return response()->json([
                'success' => true,
                'message' => 'Social links updated',
                'data' => $this->present($social),
            ]);
        } catch (Throwable $e) {
            Log::error('Failed to update socials', [
                'user_id' => $user->id,
                'message' => $e->getMessage(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Unable to update social links at this time',
            ], 500);
        }
    }

    private function present(?Social $social): array
    {
        return [
            'facebook' => $social?->facebook,
            'instagram' => $social?->instagram,
            'x' => $social?->twitter,
            'linkedin' => $social?->linkedin,
            'pinterest' => $social?->pinterest,
        ];
    }

    private function nullableString(?string $value): ?string
    {
        $value = is_string($value) ? trim($value) : $value;

        return $value === '' || $value === null ? null : $value;
    }
}
