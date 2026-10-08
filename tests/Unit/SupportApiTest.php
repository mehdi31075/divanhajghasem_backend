<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use PHPUnit\Framework\TestCase;

final class SupportApiTest extends TestCase
{
    public function test_public_text_message_can_be_retrieved_by_receipt_and_answered_by_a_token_admin(): void
    {
        $now = 1_800_000_000;
        $receiptHash = null;
        $message = null;
        $store = $this->createMock(DivanRepository::class);
        $store->method('supportCountFromIp')->willReturn(0);
        $store->expects($this->once())->method('createSupportMessage')->willReturnCallback(
            function ($hash, $ipHash, $body, $createdAt) use (&$receiptHash, &$message): void {
                $receiptHash = $hash;
                $message = ['id' => 12, 'message' => $body, 'reply' => null, 'created_at' => $createdAt, 'replied_at' => null];
            },
        );
        $store->method('supportMessage')->willReturnCallback(function ($hash) use (&$receiptHash, &$message) {
            return $hash === $receiptHash ? $message : null;
        });
        $store->method('token')->willReturn([
            'username' => 'admin',
            'password_digest' => hash('sha256', 'stored-password'),
            'expires_at' => $now + 100,
        ]);
        $store->method('user')->willReturn(['Username' => 'admin', 'Password' => 'stored-password']);
        $store->expects($this->once())->method('supportMessages')->willReturnCallback(function () use (&$message) {
            return [$message];
        });
        $store->expects($this->once())->method('replyToSupport')->with('12', 'پاسخ مدیر', $now)->willReturnCallback(
            function ($id, $reply, $repliedAt) use (&$message): int {
                $message['reply'] = $reply;
                $message['replied_at'] = $repliedAt;

                return 1;
            },
        );

        $api = new DivanApi($store);
        $created = $api->handle('POST', 'support_create', ['message' => "پیشنهاد فارسی\nمتن"], null, '127.0.0.1', $now);
        $this->assertMatchesRegularExpression('/^[a-f0-9]{32}$/', $created['receipt']);
        $this->assertSame(hash('sha256', $created['receipt']), $receiptHash);

        $ticket = $api->handle('POST', 'support_check', ['receipt' => $created['receipt']], null, '127.0.0.1', $now);
        $this->assertSame("پیشنهاد فارسی\nمتن", $ticket['ticket']['message']);
        $this->assertNull($ticket['ticket']['reply']);

        $token = str_repeat('a', 64);
        $adminMessages = $api->handle('GET', 'support_list', [], $token, '127.0.0.1', $now);
        $this->assertSame('12', $adminMessages['messages'][0]['id']);
        $api->handle('POST', 'support_reply', ['id' => '12', 'reply' => 'پاسخ مدیر'], $token, '127.0.0.1', $now);
        $answered = $api->handle('POST', 'support_check', ['receipt' => $created['receipt']], null, '127.0.0.1', $now);
        $this->assertSame('پاسخ مدیر', $answered['ticket']['reply']);
    }

    public function test_public_support_message_rejects_blank_and_rate_limited_requests(): void
    {
        $store = $this->createStub(DivanRepository::class);
        $api = new DivanApi($store);
        try {
            $api->handle('POST', 'support_create', ['message' => '  '], null, 'fixture', 100);
            $this->fail('Blank support message accepted');
        } catch (ApiError $error) {
            $this->assertSame(422, $error->status);
        }

        $store = $this->createStub(DivanRepository::class);
        $store->method('supportCountFromIp')->willReturn(5);
        try {
            (new DivanApi($store))->handle('POST', 'support_create', ['message' => 'متن'], null, 'fixture', 100);
            $this->fail('Rate-limited support message accepted');
        } catch (ApiError $error) {
            $this->assertSame(429, $error->status);
        }
    }
}
