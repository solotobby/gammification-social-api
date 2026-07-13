<?php

namespace App\Services;

use App\Models\UserLevel;

class UserServices{

    public function activeLevel($user){ //fetches a user active level
       $level = UserLevel::where('user_id', $user->id)->where('status', 'active')->first();

       return $level->plan_name;
    }

}