<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Override;

class UserResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */

   
    public function toArray(Request $request): array
    {
        // return parent::toArray($request);
        return [
            'id' => $this->id,
            'name' => $this->name,
            'username' => $this->username,
            'email' => $this->email,
            'referral_code' => $this->referral_code,
            'is_onboarded' => $this->is_onboarded,
            'status' => $this->status,
            'email_verified_at' => $this->email_verified_at,
            // 'activeLevel' => 
            'created_at' => $this->created_at,

            // 'level' => $this->whenLoaded('activeLevel', function () {
            //    return [
            //     'plan' => $this->plan_name
            //    ];
               
            // }),


            // 'wallet' => $this->whenLoaded('wallet', function () {
            //     return [
            //         'balance' => $this->wallet->balance,
            //         'promoter_balance' => $this->wallet->promoter_balance,
            //         'referral_balance' => $this->wallet->referral_balance,
            //         'currency' => $this->wallet->currency,
            //         'level' => $this->wallet->level,
            //     ];
            // }),
        ];
    }
}
