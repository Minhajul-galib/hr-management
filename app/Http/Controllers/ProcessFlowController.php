<?php

namespace App\Http\Controllers;

use Inertia\Inertia;

class ProcessFlowController extends Controller
{
    public function index()
    {
        return Inertia::render('ProcessFlow/Index');
    }
}
