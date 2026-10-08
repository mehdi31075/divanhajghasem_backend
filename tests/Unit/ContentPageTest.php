<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Bootstrap\HandleExceptions;
use Illuminate\Http\Request;
use PHPUnit\Framework\TestCase;

final class ContentPageTest extends TestCase
{
    public function test_public_pages_use_http_without_auth_or_cookies_and_allow_browser_reads(): void
    {
        $app = require __DIR__.'/../../bootstrap/app.php';
        $app->make(ConsoleKernel::class)->bootstrap();
        config(['divan.require_https' => true]);
        $rows = json_decode(file_get_contents(__DIR__.'/../../database/data/content_pages.json'), true);
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->once())->method('pages')->willReturn($rows);
        $app->instance(DivanRepository::class, $store);
        try {
            $request = Request::create('http://localhost/pages.php');
            $request->headers->set('Origin', 'http://localhost:8081');
            $response = $app->make(HttpKernel::class)->handle($request);
            $this->assertSame(200, $response->getStatusCode());
            $this->assertSame(['pages' => $rows], json_decode($response->getContent(), true));
            $this->assertSame('*', $response->headers->get('Access-Control-Allow-Origin'));
            $this->assertFalse($response->headers->has('Location'));
            $this->assertFalse($response->headers->has('Set-Cookie'));
            $this->assertSame(405, $app->make(HttpKernel::class)->handle(Request::create('http://localhost/pages.php', 'POST'))->getStatusCode());
        } finally {
            HandleExceptions::flushState($this);
        }
    }

    public function test_anonymous_page_writes_are_rejected_before_touching_the_database(): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->never())->method('updatePage');
        $this->expectException(ApiError::class);
        (new DivanApi($store))->handle('POST', 'page_update', [], null, 'fixture');
    }

    public function test_stale_revision_cannot_overwrite_an_admins_newer_page(): void
    {
        $store = $this->createMock(DivanRepository::class);
        $store->method('token')->willReturn(['username' => 'fixture', 'expires_at' => 9999, 'password_digest' => hash('sha256', 'hash')]);
        $store->method('user')->willReturn(['Username' => 'fixture', 'Password' => 'hash']);
        $store->expects($this->exactly(2))->method('updatePage')->with('first-talk', 'سخن اول', '<p>متن</p>', '1', 100)->willReturnOnConsecutiveCalls(1, 0);
        $api = new DivanApi($store);
        $input = ['slug' => 'first-talk', 'title' => 'سخن اول', 'html_body' => '<p>متن</p>', 'revision' => '1'];
        $this->assertSame(['ok' => true], $api->handle('POST', 'page_update', $input, str_repeat('a', 64), 'fixture', 100));
        try {
            $api->handle('POST', 'page_update', $input, str_repeat('a', 64), 'fixture', 100);
            $this->fail('Stale page accepted');
        } catch (ApiError $error) {
            $this->assertSame(409, $error->status);
            $this->assertSame('page_changed', $error->errorCode);
        }
    }
}
