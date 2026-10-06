<?php

namespace Tests\Unit;

use App\Services\DivanRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LegacyReaderRoutingTest extends TestCase
{
    private Application $app;

    protected function setUp(): void
    {
        parent::setUp();
        $this->app = require __DIR__.'/../../bootstrap/app.php';
        $this->app->make(ConsoleKernel::class)->bootstrap();
        config(['divan.require_https' => true]);
    }

    protected function tearDown(): void
    {
        HandleExceptions::flushState($this);
        parent::tearDown();
    }

    public static function apkReaderUrls(): array
    {
        return [
            'normal categories' => ['/api.php', []],
            'APK categories' => ['//api.php', []],
            'APK category articles' => ['//api.php?cat_id=61', ['cat_id' => '61']],
            'APK article detail' => ['//api.php?nid=85', ['nid' => '85']],
            'legacy limit' => ['//api.php?latest_news=20', ['latest_news' => '20']],
            'query precedence' => ['//api.php?cat_id=61&nid=85', ['cat_id' => '61']],
        ];
    }

    #[DataProvider('apkReaderUrls')]
    public function test_public_http_reader_accepts_apk_urls_without_redirect_or_auth(string $path, array $query): void
    {
        $rows = [['cid' => '61', 'category_name' => 'حضرت زینب(سلام الله)', 'category_image' => '9370-2024-03-11.png', 'author' => 'به قلم: قاسم رستمی', 'status' => '1']];
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->once())->method('articles')->with($query)->willReturn($rows);
        $this->app->instance(DivanRepository::class, $store);
        $request = Request::create('http://localhost'.$path);
        $request->headers->set('User-Agent', 'Apache-HttpClient/UNAVAILABLE (java 1.4)');
        $response = $this->app->make(HttpKernel::class)->handle($request);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Location'));
        $this->assertFalse($response->headers->has('Set-Cookie'));
        $this->assertSame(['AndroidEbookApp' => $rows], json_decode($response->getContent(), true));
    }

    public function test_apk_reader_alias_does_not_accept_writes(): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->never())->method('articles');
        $this->app->instance(DivanRepository::class, $store);
        $request = Request::create('http://localhost//api.php', 'POST');
        $response = $this->app->make(HttpKernel::class)->handle($request);

        $this->assertSame(405, $response->getStatusCode());
        $this->assertFalse($response->headers->has('Location'));
    }
}
