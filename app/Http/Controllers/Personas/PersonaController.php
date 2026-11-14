<?php

namespace App\Http\Controllers\Personas;

use App\Http\Controllers\Controller;
use Illuminate\View\View;

class PersonaController extends Controller
{
    public function index(): View
    {
        return view('personas.index');
    }
    
}
