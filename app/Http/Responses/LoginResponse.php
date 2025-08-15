<?php

namespace App\Http\Responses;

use Laravel\Fortify\Contracts\LoginResponse as LoginResponseContract;

class LoginResponse implements LoginResponseContract
{
    public function toResponse($request)
    {
        // Se c'è una URL "intended" la usa, altrimenti "/" (HOME)
        return redirect()->intended(url(\App\Providers\RouteServiceProvider::HOME));
    }
}
