<?php

namespace App\Http\Controllers;

use App\Services\LookupService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class LookupController extends Controller
{
    /** Position titles matching what the user has typed (about 2,000 in the list). */
    public function positions(Request $request, LookupService $lookups): JsonResponse
    {
        $request->validate(['q' => ['nullable', 'string', 'max:100']]);

        return response()->json($lookups->searchPositions((string) $request->query('q', '')));
    }
}
