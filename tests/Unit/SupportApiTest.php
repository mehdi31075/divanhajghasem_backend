<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use PHPUnit\Framework\TestCase;

final class SupportApiTest extends TestCase
{
    public function test_phone_otp_authenticates_support_messages_and_replies(): void
    {
        $now = 1_800_000_000;
        $challenge = null;
        $otpHash = null;
        $issuedTokenHash = null;
        $store = $this->createMock(DivanRepository::class);
        $store->expects($this->once())->method('deleteOldSupportOtps')->with($now - 3600);
        $store->method('supportOtpCountFromIp')->willReturn(0);
        $store->method('supportUserByPhone')->with('+989123456789')->willReturn(null);
        $store->expects($this->once())->method('saveSupportUser')->with('کاربر آزمایشی', '+989123456789', $now)->willReturn([
            'id' => 22, 'name' => 'کاربر آزمایشی', 'mobile' => '+989123456789',
        ]);
        $store->expects($this->once())->method('createSupportOtp')->willReturnCallback(
            function ($id, $userId, $mobile, $hash, $ipHash, $created, $expires) use (&$challenge, &$otpHash, $now): void {
                $challenge = $id;
                $otpHash = $hash;
                self::assertNull($userId);
                self::assertSame('+989123456789', $mobile);
                self::assertSame($now + 300, $expires);
            },
        );
        $store->expects($this->exactly(2))->method('supportOtp')->willReturnCallback(
            function ($id) use (&$challenge, &$otpHash, $now): array {
                self::assertSame($challenge, $id);

                return ['challenge_id' => $id, 'user_id' => null, 'mobile' => '+989123456789', 'code_hash' => $otpHash, 'attempts' => 0, 'expires_at' => $now + 300, 'consumed_at' => null];
            },
        );
        $store->expects($this->exactly(2))->method('incrementSupportOtpAttempts')->with($this->callback(function ($id) use (&$challenge): bool { return $id === $challenge; }));
        $store->expects($this->once())->method('consumeSupportOtp')->with($this->callback(function ($id) use (&$challenge): bool { return $id === $challenge; }), $now);
        $store->expects($this->once())->method('issueSupportToken')->willReturnCallback(
            function ($hash, $userId, $created, $expires) use (&$issuedTokenHash, $now): void {
                $issuedTokenHash = $hash;
                self::assertSame(22, (int) $userId);
                self::assertSame($now + 2_592_000, $expires);
            },
        );
        $store->method('supportUser')->with(22)->willReturn([
            'id' => 22, 'name' => 'کاربر آزمایشی', 'mobile' => '+989123456789',
        ]);
        $store->method('supportToken')->willReturnCallback(function ($hash) use (&$issuedTokenHash, $now): ?array {
            self::assertSame($issuedTokenHash, $hash);

            return ['user_id' => 22, 'expires_at' => $now + 2_592_000];
        });
        $store->expects($this->once())->method('supportMessageCountForUser')->with(22, $now - 3600)->willReturn(0);
        $store->expects($this->once())->method('createSupportMessage')->with(22, hash('sha256', '127.0.0.1'), 'پیشنهاد فارسی', $now);
        $store->expects($this->once())->method('supportMessagesForUser')->with(22)->willReturn([
            ['id' => 12, 'message' => 'پیشنهاد فارسی', 'reply' => 'پاسخ مدیر', 'created_at' => $now, 'replied_at' => $now],
        ]);

        $api = new DivanApi($store);
        $started = $api->handle('POST', 'user_start', ['mobile' => '۰۹۱۲۳۴۵۶۷۸۹'], null, '127.0.0.1', $now);
        $this->assertSame('register', $started['mode']);
        $this->assertMatchesRegularExpression('/^[0-9]{6}$/', $started['test_otp']);
        $this->assertArrayNotHasKey('receipt', $started);

        $needsName = $api->handle('POST', 'user_verify', ['challenge_id' => $started['challenge_id'], 'otp' => $started['test_otp']], null, '127.0.0.1', $now);
        $this->assertTrue($needsName['needs_name']);
        $verified = $api->handle('POST', 'user_verify', ['challenge_id' => $started['challenge_id'], 'otp' => $started['test_otp'], 'name' => 'کاربر آزمایشی'], null, '127.0.0.1', $now);
        $supportToken = $verified['access_token'];
        $this->assertSame(hash('sha256', $supportToken), $issuedTokenHash);
        $api->handle('POST', 'support_send', ['message' => 'پیشنهاد فارسی'], $supportToken, '127.0.0.1', $now);
        $messages = $api->handle('GET', 'support_mine', [], $supportToken, '127.0.0.1', $now);
        $this->assertSame('پاسخ مدیر', $messages['messages'][0]['reply']);
        $this->assertArrayNotHasKey('receipt', $messages['messages'][0]);
    }

    public function test_support_requires_verified_session_and_retires_receipts(): void
    {
        $api = new DivanApi($this->createStub(DivanRepository::class));
        try {
            $api->handle('POST', 'support_send', ['message' => 'متن'], null, 'fixture', 100);
            $this->fail('Unauthenticated support message accepted');
        } catch (ApiError $error) {
            $this->assertSame(401, $error->status);
        }
        try {
            $api->handle('POST', 'support_create', ['message' => 'متن'], null, 'fixture', 100);
            $this->fail('Receipt-based support flow was not retired');
        } catch (ApiError $error) {
            $this->assertSame(410, $error->status);
        }
        try {
            $api->handle('POST', 'support_start', ['name' => 'نام', 'mobile' => '123'], null, 'fixture', 100);
            $this->fail('Invalid mobile number accepted');
        } catch (ApiError $error) {
            $this->assertSame(422, $error->status);
        }
    }

    public function test_otp_requests_are_rate_limited(): void
    {
        $store = $this->createStub(DivanRepository::class);
        $store->method('supportOtpCountFromIp')->willReturn(10);
        try {
            (new DivanApi($store))->handle('POST', 'support_start', ['name' => 'نام', 'mobile' => '09123456789'], null, 'fixture', 100);
            $this->fail('Rate-limited OTP request accepted');
        } catch (ApiError $error) {
            $this->assertSame(429, $error->status);
        }
    }
}
