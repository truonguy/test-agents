<?php

namespace Tests\Feature\Support;

use App\Models\Category;
use App\Services\Support\PaginationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PaginationServiceTest extends TestCase
{
    use RefreshDatabase;

    private PaginationService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new PaginationService;
    }

    public function test_resolve_per_page_defaults_and_clamps(): void
    {
        $this->assertSame(15, $this->service->resolvePerPage(null));
        $this->assertSame(15, $this->service->resolvePerPage(0));       // <1 → default
        $this->assertSame(15, $this->service->resolvePerPage('abc'));   // không hợp lệ → default
        $this->assertSame(25, $this->service->resolvePerPage(25));
        $this->assertSame(100, $this->service->resolvePerPage(9999));   // clamp trần
    }

    public function test_paginate_and_format_envelope(): void
    {
        Category::factory()->count(7)->create();

        $paginator = $this->service->paginate(Category::query(), 5);
        $formatted = $this->service->format($paginator);

        $this->assertArrayHasKey('data', $formatted);
        $this->assertArrayHasKey('meta', $formatted);
        $this->assertCount(5, $formatted['data']);
        $this->assertSame(7, $formatted['meta']['total']);
        $this->assertSame(5, $formatted['meta']['per_page']);
        $this->assertSame(2, $formatted['meta']['last_page']);
        $this->assertSame(1, $formatted['meta']['current_page']);
    }
}
