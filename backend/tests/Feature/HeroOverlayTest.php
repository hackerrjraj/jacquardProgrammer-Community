<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Setting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Hero slider "Overlay darkness" setting (Site Content → Images): 0–80, default 40, sent with the public slides. */
class HeroOverlayTest extends TestCase
{
    use RefreshDatabase;

    private const EN = ['Accept' => 'application/json', 'Accept-Language' => 'en'];

    public function test_default_is_40_and_public_slides_carry_it(): void
    {
        $this->getJson('/api/hero-slides', self::EN)->assertOk()->assertJsonPath('overlay', 40);
        $this->actingAs(User::factory()->admin()->create())
            ->getJson('/api/admin/hero-overlay', self::EN)
            ->assertOk()->assertJsonPath('data.overlay', 40)->assertJsonPath('data.max', 80);
    }

    public function test_admin_saves_value_and_it_is_audited(): void
    {
        $admin = User::factory()->admin()->create();
        $this->actingAs($admin)->putJson('/api/admin/hero-overlay', ['overlay' => 15], self::EN)
            ->assertOk()->assertJsonPath('data.overlay', 15);

        $this->assertSame('15', Setting::valueOf(Setting::HERO_OVERLAY));
        $this->getJson('/api/hero-slides', self::EN)->assertJsonPath('overlay', 15);
        $this->assertSame(1, AuditLog::where('action', 'hero_overlay.updated')->count());

        // Same value again → no second audit entry.
        $this->actingAs($admin)->putJson('/api/admin/hero-overlay', ['overlay' => 15], self::EN)->assertOk();
        $this->assertSame(1, AuditLog::where('action', 'hero_overlay.updated')->count());
    }

    public function test_range_is_0_to_80(): void
    {
        $admin = User::factory()->admin()->create();
        foreach ([0, 80] as $ok) {
            $this->actingAs($admin)->putJson('/api/admin/hero-overlay', ['overlay' => $ok], self::EN)->assertOk();
        }
        foreach ([-1, 81, 'dark', null] as $bad) {
            $this->actingAs($admin)->putJson('/api/admin/hero-overlay', ['overlay' => $bad], self::EN)
                ->assertStatus(422)->assertJsonValidationErrors('overlay');
        }
    }

    public function test_only_admins_can_use_it(): void
    {
        $this->getJson('/api/admin/hero-overlay', self::EN)->assertUnauthorized();
        $this->putJson('/api/admin/hero-overlay', ['overlay' => 10], self::EN)->assertUnauthorized();

        $this->actingAs(User::factory()->approved()->create());
        $this->putJson('/api/admin/hero-overlay', ['overlay' => 10], self::EN)->assertForbidden();
        $this->assertNull(Setting::valueOf(Setting::HERO_OVERLAY));
    }
}
