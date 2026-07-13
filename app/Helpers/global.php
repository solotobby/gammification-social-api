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