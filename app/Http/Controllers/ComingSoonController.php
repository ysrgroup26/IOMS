<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * Future Departments (v1.9.0/v1.10.0): Warehouse, Procurement, Asset
 * Management, Maintenance, Quality Control, Finance. One shared page
 * rather than six near-identical controllers -- these departments stay
 * visible in the Department selector (per explicit instruction) but have
 * no real module build-out yet.
 */
class ComingSoonController extends Controller
{
    private const LABELS = [
        'warehouse' => 'Warehouse',
        'procurement' => 'Procurement',
        'asset-management' => 'Asset Management',
        'maintenance' => 'Maintenance',
        'quality-control' => 'Quality Control',
        'finance' => 'Finance',
    ];

    public function show(Request $request, string $department): Response
    {
        if (! isset(self::LABELS[$department])) {
            throw new NotFoundHttpException;
        }

        return Inertia::render('ComingSoon', [
            'department' => $department,
            'label' => self::LABELS[$department],
        ]);
    }
}
