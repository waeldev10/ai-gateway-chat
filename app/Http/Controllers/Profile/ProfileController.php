<?php

namespace App\Http\Controllers\Profile;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class ProfileController extends Controller
{

    public function show(): View
    {
        return view('profile.show');
    }
    
}
