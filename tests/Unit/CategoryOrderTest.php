<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use PHPUnit\Framework\TestCase;

final class CategoryOrderTest extends TestCase
{
    public function test_saved_order_keeps_legacy_fields_and_appends_new_categories(): void
    {
        $rows = [['cid' => '61', 'category_name' => 'اول'], ['cid' => '62', 'category_name' => 'دوم'], ['cid' => '63', 'category_name' => 'تازه']];
        $this->assertSame([$rows[0], $rows[1], $rows[2]], DivanRepository::orderedCategories($rows, ['61', '999', '62']));
        $this->assertSame([$rows[2], $rows[1], $rows[0]], DivanRepository::orderedCategories($rows, []));
    }

    public function test_anonymous_reordering_is_rejected_before_storage_changes(): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->never())->method('reorderCategories');
        try {
            (new DivanApi($store))->handle('POST', 'category_reorder', ['ids' => '[61,62]'], null, '127.0.0.1', 100);
            $this->fail('Anonymous mutation succeeded');
        } catch (ApiError $e) {
            $this->assertStringContainsString('وارد', $e->getMessage());
        }
    }

    public function test_order_requires_distinct_ids_and_passes_baseline_to_repository(): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->method('token')->willReturn(['username' => 'admin', 'expires_at' => 999, 'password_digest' => hash('sha256', 'hash')]);
        $store->method('user')->willReturn(['Username' => 'admin', 'Password' => 'hash']);
        $store->expects($this->once())->method('reorderCategories')->with(['61', '62'], ['62', '61']);
        $api = new DivanApi($store);
        $result = $api->handle('POST', 'category_reorder', ['ids' => '[61,62]', 'previous_ids' => '[62,61]'], str_repeat('a', 64), '127.0.0.1', 100);
        $this->assertSame(['ok' => true, 'ids' => ['61', '62']], $result);
        $this->expectException(ApiError::class);
        $api->handle('POST', 'category_reorder', ['ids' => '[61,61]', 'previous_ids' => '[62,61]'], str_repeat('a', 64), '127.0.0.1', 100);
    }
}
