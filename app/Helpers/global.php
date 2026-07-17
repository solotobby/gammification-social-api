<?php

use App\Models\User;

if (!function_exists('userLevel')) {
    function userLevel($userId = null)
    {
        
        // $user = $userId ? User::find($userId) : auth()->user();

        return $user?->activeLevel?->plan_name ?? 'Basic';

        // return $userId ? User::find($userId)->activeLevel->plan_name : auth()->user()->activeLevel->plan_name;
    }
}

if (!function_exists('calculateUniqueEarningPerLike')) {
    function calculateUniqueEarningPerLike()
    {
        if (userLevel() == 'Basic' || userLevel() == 'Creator') {
            return 0.00002;
        } else {
            return 0.0004;
        }
    }
}

if (!function_exists('displayName')) {
    function displayName($name)
    {
        $bk = explode(' ', $name);
        return $bk[0];
    }
}

// if (!function_exists('userActivity')) {
//     function userActivity($event)
//     {
//         UserActivity::create([
//             'user_id' => auth()->user()->id,
//             'event' => $event
//         ]);
//     }
// }

