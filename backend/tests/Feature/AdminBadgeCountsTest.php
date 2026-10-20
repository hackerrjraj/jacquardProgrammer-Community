<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** Sidebar red badges: GET /api/admin/badge-counts (admin + super_admin only). */
class AdminBadgeCountsTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('private');
        $this->travelTo('2026-10-15 12:00:00');
        Setting::put(Setting::FUND_START_MONTH, '2026-08');
        $this->withHeader('Origin', 'http://localhost:3000');
    }

    public function test_only_admins_can_read_the_counts(): void
    {
        $this->getJson('/api/admin/badge-counts')->assertUnauthorized();
        foreach (['approved' => User::factory()->approved(), 'committee' => User::factory()->committee()] as $factory) {
            $this->actingAs($factory->create())->getJson('/api/admin/badge-counts', self::EN)->assertForbidden();
        }
        foreach ([User::factory()->admin(), User::factory()->superAdmin()] as $factory) {
            $this->actingAs($factory->create())->getJson('/api/admin/badge-counts', self::EN)->assertOk();
        }
    }

    public function test_counts_start_at_zero(): void
    {
        $this->actingAs(User::factory()->admin()->create())->getJson('/api/admin/badge-counts', self::EN)
            ->assertOk()
            ->assertExactJson(['data' => [
                'pending_members' => 0,
                'pending_payments' => 0,
                'pending_aid' => 0,
                'open_chat_reports' => 0,
                'unread_contact_messages' => 0,
                'committee_chat_unread' => 0,
            ]]);
    }

    public function test_counts_follow_the_queues_and_drop_after_a_decision(): void
    {
        $admin = User::factory()->admin()->create();
        $member = User::factory()->approved()->create(['approved_at' => '2026-08-10 09:00:00']);
        $newcomer = User::factory()->create(); // pending sign-up
        User::factory()->create();
        ContactMessage::create(['name' => 'V', 'phone' => '01711111111', 'subject' => 'Hi', 'message' => 'Hello']);

        $this->actingAs($member)->post('/api/contributions', [
            'method' => 'bkash', 'sender_number' => '01712345678', 'transaction_id' => 'ABC123XYZ',
            'amount' => 200, 'months' => ['2026-08'],
        ], self::EN)->assertCreated();
        $this->actingAs($member)->post('/api/assistance', [
            'reason_type' => 'medical', 'description' => 'Hospital bill after an operation.', 'amount_requested' => 15000,
        ], self::EN)->assertCreated();

        $this->actingAs($admin)->getJson('/api/admin/badge-counts', self::EN)
            ->assertOk()
            ->assertJsonPath('data.pending_members', 2)
            ->assertJsonPath('data.pending_payments', 1)
            ->assertJsonPath('data.pending_aid', 1)
            ->assertJsonPath('data.unread_contact_messages', 1);

        $this->actingAs($admin)->post("/api/admin/members/{$newcomer->id}/approve", [], self::EN)->assertOk();

        $this->actingAs($admin)->getJson('/api/admin/badge-counts', self::EN)
            ->assertJsonPath('data.pending_members', 1);
    }
}
