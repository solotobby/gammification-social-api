<?php

namespace App\Services;

use App\Models\UserLevel;

use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;


class UserServices{

    public function activeLevel($user){ //fetches a user active level
       $level = UserLevel::where('user_id', $user->id)->where('status', 'active')->first();

       return $level?->plan_name;
    }


     private const SEARCH_COLUMNS = ['id', 'name', 'username', 'avatar', 'followers', 'following'];



      public function search(string $term, int $perPage = 10): LengthAwarePaginator
    {
        $term = trim($term);

        if ($term === '') {
            return User::query()->whereRaw('1 = 0')->paginate($perPage); // empty result set, still a valid paginator shape
        }

        return User::query()
            ->select(self::SEARCH_COLUMNS)
            ->search($term)
            ->orderBy('name')
            ->paginate($perPage);
    }



}