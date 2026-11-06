<?php

declare(strict_types=1);

namespace App\Strategy\Http;

use App\Strategy\Domain\MethodCategory;
use App\Strategy\Domain\MethodImplementationStatus;
use App\Strategy\Domain\MethodType;
use App\Strategy\MethodRegistry;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Symfony\Component\HttpFoundation\Response;

final class MethodCatalogController extends Controller
{
    public function index(Request $request, MethodRegistry $registry): JsonResponse
    {
        $category = null;
        if ($catStr = $request->query('category')) {
            $category = MethodCategory::tryFrom((string) $catStr);
        }

        $type = null;
        if ($typeStr = $request->query('type')) {
            $type = MethodType::tryFrom((string) $typeStr);
        }

        $status = null;
        if ($statusStr = $request->query('status')) {
            $status = MethodImplementationStatus::tryFrom((string) $statusStr);
        }

        $methods = $registry->filter($category, $type, $status);

        // Group counts by category
        $byCategory = [];
        foreach (MethodCategory::cases() as $case) {
            $byCategory[$case->value] = count($registry->filter(category: $case));
        }

        // Group counts by status
        $byStatus = [];
        foreach (MethodImplementationStatus::cases() as $case) {
            $byStatus[$case->value] = count($registry->filter(status: $case));
        }

        return response()->json([
            'data' => array_map(fn ($m) => $m->toArray(), $methods),
            'total' => count($methods),
            'summary' => [
                'total_methods' => 50,
                'by_category' => $byCategory,
                'by_status' => $byStatus,
            ],
        ]);
    }

    public function show(string $id, MethodRegistry $registry): JsonResponse
    {
        $method = $registry->find($id);
        if ($method === null) {
            return response()->json([
                'error' => [
                    'code' => 'METHOD_NOT_FOUND',
                    'message' => "Trading method '{$id}' is not in the catalog.",
                ],
            ], Response::HTTP_NOT_FOUND);
        }

        return response()->json([
            'data' => $method->toArray(),
        ]);
    }
}
