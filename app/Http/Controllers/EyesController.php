<?php

namespace App\Http\Controllers;

use App\Services\EyeMatchService;
use Inertia\Inertia;

class EyesController extends Controller
{
    public function __construct(private EyeMatchService $service) {}

    public function index()
    {
        return Inertia::render('Eyes/Index', [
            'eyes' => $this->service->listEyes(),
        ]);
    }
}
