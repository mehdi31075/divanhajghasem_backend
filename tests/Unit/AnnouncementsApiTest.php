<?php

namespace Tests\Unit;

use App\Exceptions\ApiError;
use App\Services\DivanApi;
use App\Services\DivanRepository;
use PHPUnit\Framework\TestCase;

final class AnnouncementsApiTest extends TestCase
{
    public function test_user_announcements_returns_targeted_announcements_for_logged_in_user(): void
    {
        $now = 1_800_000_000;
        $token = str_repeat('a', 64);
        $tokenHash = hash('sha256', $token);

        $store = $this->createMock(DivanRepository::class);
        $store->method('supportToken')->with($tokenHash)->willReturn([
            'user_id' => 10,
            'expires_at' => $now + 1000,
        ]);
        $store->method('supportUser')->with(10)->willReturn([
            'id' => 10,
            'name' => 'علی',
            'mobile' => '+989120000000',
        ]);
        $store->method('announcementsForUser')->with(10)->willReturn([
            [
                'id' => 1,
                'title' => 'اعلام ۱',
                'subtitle' => 'توضیح کوتاه',
                'description' => '<p>متن اعلام</p>',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $api = new DivanApi($store, 'https://divanansar.ir');
        $response = $api->handle('GET', 'user_announcements', [], $token, '127.0.0.1', $now);

        self::assertTrue($response['ok']);
        self::assertCount(1, $response['announcements']);
        self::assertSame('1', $response['announcements'][0]['id']);
        self::assertSame('اعلام ۱', $response['announcements'][0]['title']);
    }

    public function test_user_announcements_requires_authentication(): void
    {
        $now = 1_800_000_000;
        $store = $this->createMock(DivanRepository::class);
        $store->method('supportToken')->willReturn(null);

        $api = new DivanApi($store, 'https://divanansar.ir');

        try {
            $api->handle('GET', 'user_announcements', [], str_repeat('b', 64), '127.0.0.1', $now);
            $this->fail('Unauthenticated user announcements request accepted');
        } catch (ApiError $error) {
            $this->assertSame(401, $error->status);
        }
    }

    public function test_admin_can_crud_announcements(): void
    {
        $now = 1_800_000_000;
        $adminToken = str_repeat('c', 64);
        $store = $this->createMock(DivanRepository::class);
        $store->method('token')->with(hash('sha256', $adminToken))->willReturn([
            'username' => 'admin',
            'password_digest' => hash('sha256', 'secret'),
            'expires_at' => $now + 1000,
        ]);
        $store->method('user')->with('admin')->willReturn([
            'username' => 'admin',
            'Password' => 'secret',
        ]);

        $store->expects($this->once())->method('createAnnouncement')->with(
            'مراسم هفتگی',
            'پاییز',
            '<p>محتوا</p>',
            [10, 20],
            $now
        )->willReturn(5);

        $api = new DivanApi($store, 'https://divanansar.ir');

        $createRes = $api->handle('POST', 'announcement_create', [
            'title' => 'مراسم هفتگی',
            'subtitle' => 'پاییز',
            'description' => '<p>محتوا</p>',
            'user_ids' => json_encode([10, 20]),
        ], $adminToken, '127.0.0.1', $now);

        self::assertTrue($createRes['ok']);
        self::assertSame('5', $createRes['id']);

        // Test querying single announcement (GROUP_CONCAT returns comma string)
        $store->method('announcement')->with(5)->willReturn([
            'id' => 5,
            'title' => 'مراسم هفتگی',
            'subtitle' => 'پاییز',
            'description' => '<p>محتوا</p>',
            'created_at' => $now,
            'updated_at' => $now,
            'user_ids' => '10,20',
        ]);

        $singleRes = $api->handle('GET', 'announcements', ['id' => 5], $adminToken, '127.0.0.1', $now);
        self::assertTrue($singleRes['ok']);
        self::assertSame('5', $singleRes['announcement']['id']);
        self::assertSame(['10', '20'], $singleRes['announcement']['user_ids']);
    }

    public function test_announcement_views_and_comments(): void
    {
        $now = 1_800_000_000;
        $userToken = str_repeat('d', 64);
        $store = $this->createMock(DivanRepository::class);
        $store->method('supportToken')->with(hash('sha256', $userToken))->willReturn([
            'user_id' => 15,
            'expires_at' => $now + 1000,
        ]);
        $store->method('supportUser')->with(15)->willReturn([
            'id' => 15,
            'name' => 'کاربر آزمایشی',
            'mobile' => '09121234567',
            'father_name' => 'پدر',
            'is_admin' => 0,
        ]);

        $store->expects($this->once())->method('recordAnnouncementView')->with(5, 15, $now);
        $store->expects($this->once())->method('createAnnouncementComment')->with(5, 15, null, 'یک نظر تستی', $now)->willReturn(101);
        $store->method('announcementComments')->with(5)->willReturn([
            [
                'id' => 101,
                'announcement_id' => 5,
                'user_id' => 15,
                'user_name' => 'کاربر آزمایشی',
                'user_is_admin' => 0,
                'parent_id' => null,
                'comment' => 'یک نظر تستی',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $api = new DivanApi($store, 'https://divanansar.ir');

        $viewRes = $api->handle('POST', 'announcement_view', ['id' => 5], $userToken, '127.0.0.1', $now);
        self::assertTrue($viewRes['ok']);

        $commentRes = $api->handle('POST', 'announcement_comment_create', [
            'announcement_id' => 5,
            'comment' => 'یک نظر تستی',
        ], $userToken, '127.0.0.1', $now);
        self::assertTrue($commentRes['ok']);
        self::assertSame('101', $commentRes['id']);

        $listRes = $api->handle('GET', 'announcement_comments', ['announcement_id' => 5], $userToken, '127.0.0.1', $now);
        self::assertTrue($listRes['ok']);
        self::assertCount(1, $listRes['comments']);
        self::assertSame('یک نظر تستی', $listRes['comments'][0]['comment']);
    }

    public function test_public_announcements_requires_no_auth(): void
    {
        $now = 1_800_000_000;
        $store = $this->createMock(DivanRepository::class);
        $store->method('publicAnnouncements')->willReturn([
            [
                'id' => 1,
                'title' => 'سخن اول',
                'subtitle' => 'مقدمه',
                'description' => '<p>سلام</p>',
                'is_public' => 1,
                'icon' => 'bookmark',
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ]);

        $api = new DivanApi($store, 'https://divanansar.ir');
        $res = $api->handle('GET', 'public_announcements', [], null, '127.0.0.1', $now);

        self::assertTrue($res['ok']);
        self::assertCount(1, $res['announcements']);
        self::assertSame('1', $res['announcements'][0]['id']);
        self::assertSame('سخن اول', $res['announcements'][0]['title']);
        self::assertTrue($res['announcements'][0]['is_public']);
        self::assertSame('bookmark', $res['announcements'][0]['icon']);
    }
}
