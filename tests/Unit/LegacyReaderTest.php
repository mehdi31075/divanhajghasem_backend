<?php

namespace Tests\Unit;

use App\Http\Controllers\ReaderController;
use App\Services\DivanRepository;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyReaderTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(Kernel::class)->bootstrap();
    }

    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);
        parent::tearDown();
    }

    public static function readerQueries(): array
    {
        return [
            'categories' => ['', []],
            'zero category wins over article' => ['?cat_id=0&nid=85', ['cat_id' => '0']],
            'zero article' => ['?nid=0', ['nid' => '0']],
            'zero limit' => ['?latest_news=0', ['latest_news' => '0']],
            'article wins over limit' => ['?nid=85&latest_news=20', ['nid' => '85']],
        ];
    }

    #[DataProvider('readerQueries')]
    public function test_present_zero_queries_keep_legacy_precedence_and_empty_array(string $suffix, array $query): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->once())->method('articles')->with($query)->willReturn([]);
        $response = (new ReaderController)(Request::create('/api.php'.$suffix), $store);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('[]', $response->getContent());
    }

    public function test_reader_keeps_field_order_strings_null_and_html_without_authentication(): void
    {
        $body = '<p dir="rtl">متن قدیمی</p>';
        $store = $this->createStub(DivanRepository::class);
        $store->method('articles')->willReturn([['nid' => 85, 'news_heading' => 'عنوان', 'news_image' => null, 'news_description' => $body]]);
        $response = (new ReaderController)(Request::create('/api.php?nid=85'), $store);
        $this->assertSame(['AndroidEbookApp' => [['nid' => '85', 'news_heading' => 'عنوان', 'news_image' => null, 'news_description' => $body]]], json_decode($response->getContent(), true));
    }
}
