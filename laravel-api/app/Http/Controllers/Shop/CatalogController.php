<?php

namespace App\Http\Controllers\Shop;

use App\Http\Controllers\Controller;
use App\Services\Catalog\ProductSearchService;
use App\Services\Support\PaginationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class CatalogController extends Controller
{
    public function __construct(
        private readonly ProductSearchService $search,
        private readonly PaginationService $pagination,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['category_id', 'price_min', 'price_max', 'sort', 'per_page']);

        $paginator = $this->search->search($filters);

        return response()->json($this->pagination->format($paginator));
    }
}
