<?php

namespace Tests\Feature;

use App\Models\MemberDocument;
use App\Models\ProfileListItem;
use App\Models\User;
use Database\Seeders\DemoProgrammersSeeder;
use Database\Seeders\ProfileListSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/** Phase 5a: public (no login) Programmers directory + profile API (MASTER_SPEC §21–§22). */
class PublicProgrammersTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ProfileListSeeder::class);
    }

    private function addPortfolio(User $user, string $title, int $order): void
    {
        $user->portfolioItems()->make(['category' => 'jacquard', 'title' => $title, 'description' => "About {$title}", 'sort_order' => $order])
            ->forceFill(['image_path' => "portfolio/{$title}.jpg"])
            ->save();
    }

    private function listedIds(string $query = ''): array
    {
        return collect($this->getJson('/api/public/programmers'.$query)->assertOk()->json('data'))->pluck('id')->sort()->values()->all();
    }

    public function test_only_approved_members_with_a_profile_are_listed(): void
    {
        $member = User::factory()->approved()->create();
        $committee = User::factory()->committee()->create();
        $adminProgrammer = User::factory()->admin()->create();
        User::factory()->create(); // pending
        User::factory()->rejected()->create();
        User::factory()->suspended()->create();
        User::factory()->superAdmin()->create(['district' => null, 'experience_years' => null]); // admin-only account, no profile

        $this->assertSame(collect([$member->id, $committee->id, $adminProgrammer->id])->sort()->values()->all(), $this->listedIds());
    }

    public function test_list_card_has_public_fields_only(): void
    {
        $user = User::factory()->approved()->create([
            'designation' => 'Senior Programmer',
            'employment_status' => 'running_job',
            'machines' => ['stoll', 'shima-seiki'],
            'bio' => str_repeat('a', 300),
            'phone_visibility' => 'public',
            'email_visibility' => 'public',
        ]);
        $this->addPortfolio($user, 'one', 1);
        $this->addPortfolio($user, 'two', 2);

        $card = $this->getJson('/api/public/programmers')
            ->assertOk()
            ->assertJsonStructure(['data' => [['id', 'name', 'photo_url', 'designation', 'employment_status', 'district', 'experience_years', 'machines', 'bio_excerpt', 'portfolio_count']], 'links', 'meta' => ['current_page', 'last_page', 'per_page', 'total']])
            ->json('data.0');

        $this->assertSame(2, $card['portfolio_count']);
        $this->assertSame([['key' => 'stoll', 'name_bn' => 'STOLL', 'name_en' => 'STOLL'], ['key' => 'shima-seiki', 'name_bn' => 'SHIMA SEIKI', 'name_en' => 'SHIMA SEIKI']], $card['machines']);
        $this->assertLessThanOrEqual(163, mb_strlen($card['bio_excerpt']));
        foreach (['phone', 'email', 'role', 'status', 'rejection_reason', 'approved_by', 'must_change_password', 'skills', 'documents', 'bio'] as $key) {
            $this->assertArrayNotHasKey($key, $card);
        }
    }

    public function test_pending_suspended_rejected_missing_and_admin_only_profiles_give_the_same_404(): void
    {
        $hidden = [
            User::factory()->create(),
            User::factory()->rejected()->create(),
            User::factory()->suspended()->create(),
            User::factory()->superAdmin()->create(['district' => null, 'experience_years' => null]),
        ];

        $expected = ['message' => 'This programmer could not be found.'];
        foreach ([...array_map(fn (User $u) => $u->id, $hidden), 99999, 'abc'] as $id) {
            $this->getJson("/api/public/programmers/{$id}", ['Accept-Language' => 'en'])->assertNotFound()->assertExactJson($expected);
        }

        $approved = User::factory()->approved()->create();
        $this->getJson("/api/public/programmers/{$approved->id}")->assertOk()->assertJsonPath('data.id', $approved->id);
    }

    public function test_experience_buckets(): void
    {
        $byYears = [];
        foreach ([0, 5, 6, 10, 11, 25] as $years) {
            $byYears[$years] = User::factory()->approved()->create(['experience_years' => $years])->id;
        }

        $this->assertSame([$byYears[0], $byYears[5]], $this->listedIds('?experience=0-5'));
        $this->assertSame([$byYears[6], $byYears[10]], $this->listedIds('?experience=6-10'));
        $this->assertSame([$byYears[11], $byYears[25]], $this->listedIds('?experience=10%2B'));

        $this->getJson('/api/public/programmers?experience=3-4')->assertUnprocessable()->assertJsonValidationErrors('experience');
    }

    public function test_district_machine_name_and_employment_filters(): void
    {
        $karim = User::factory()->approved()->create(['name' => 'Karim Uddin', 'district' => 'dhaka', 'machines' => ['stoll'], 'employment_status' => 'running_job']);
        $rahim = User::factory()->approved()->create(['name' => 'Rahim Mia', 'district' => 'gazipur', 'machines' => ['stoll', 'shima-seiki'], 'employment_status' => 'jobless']);
        $salma = User::factory()->approved()->create(['name' => 'Salma Karim', 'district' => 'gazipur', 'machines' => ['shima-seiki']]);

        $this->assertSame([$rahim->id, $salma->id], $this->listedIds('?district=gazipur'));
        // Machine filter = a list key (Phase 11d-1).
        $this->assertSame([$karim->id, $rahim->id], $this->listedIds('?machine=stoll'));
        $this->assertSame([$rahim->id, $salma->id], $this->listedIds('?machine=shima-seiki'));
        // An old link with the machine's name still works.
        $this->assertSame([$rahim->id, $salma->id], $this->listedIds('?machine=Shima%20Seiki'));
        $this->assertSame([], $this->listedIds('?machine=Unknown'));
        $this->assertSame([$karim->id, $salma->id], $this->listedIds('?q=karim'));
        $this->assertSame([$rahim->id], $this->listedIds('?employment_status=jobless'));
        $this->assertSame([$salma->id], $this->listedIds('?q=salma&district=gazipur&machine=shima-seiki'));

        // q searches the name only — not phone numbers.
        $this->assertSame([], $this->listedIds('?q='.substr($karim->phone, 3)));

        $this->getJson('/api/public/programmers?district=nowhere')->assertUnprocessable()->assertJsonValidationErrors('district');
    }

    public function test_pagination_and_sorting(): void
    {
        $users = collect(range(1, 14))->map(fn (int $i) => User::factory()->approved()->create([
            'name' => sprintf('Programmer %02d', $i),
            'approved_at' => now()->subDays(20 - $i), // later $i = approved more recently
            'experience_years' => $i,
        ]));

        $first = $this->getJson('/api/public/programmers')->assertOk()
            ->assertJsonCount(12, 'data')
            ->assertJsonPath('meta.per_page', 12)
            ->assertJsonPath('meta.total', 14)
            ->assertJsonPath('meta.last_page', 2);
        $this->assertSame($users[13]->id, $first->json('data.0.id')); // newest approved first

        $this->getJson('/api/public/programmers?page=2')->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('data.1.id', $users[0]->id);

        $this->getJson('/api/public/programmers?sort=name')->assertOk()->assertJsonPath('data.0.name', 'Programmer 01');
        $this->getJson('/api/public/programmers?sort=experience')->assertOk()->assertJsonPath('data.0.experience_years', 14);
        $this->getJson('/api/public/programmers?sort=random')->assertUnprocessable()->assertJsonValidationErrors('sort');
    }

    public function test_profile_has_full_bio_work_history_and_portfolio_in_order(): void
    {
        $user = User::factory()->approved()->create(['bio' => str_repeat('b', 500), 'skills' => ['m1plus']]);
        $user->workExperiences()->create(['company' => 'Second Ltd', 'position' => 'Senior', 'start_year' => 2018, 'end_year' => null, 'sort_order' => 2]);
        $user->workExperiences()->create(['company' => 'First Ltd', 'position' => 'Junior', 'start_year' => 2010, 'end_year' => 2017, 'sort_order' => 1]);
        $this->addPortfolio($user, 'later', 2);
        $this->addPortfolio($user, 'earlier', 1);

        $this->getJson("/api/public/programmers/{$user->id}")->assertOk()
            ->assertJsonPath('data.bio', str_repeat('b', 500))
            ->assertJsonPath('data.skills', [['key' => 'm1plus', 'name_bn' => 'M1PLUS', 'name_en' => 'M1PLUS']])
            ->assertJsonPath('data.work_history.0.company', 'First Ltd')
            ->assertJsonPath('data.work_history.1.end_year', 'present')
            ->assertJsonPath('data.portfolio.0.title', 'earlier')
            ->assertJsonPath('data.portfolio.1.title', 'later')
            ->assertJsonPath('data.portfolio_count', 2)
            ->assertJsonStructure(['data' => ['portfolio' => [['id', 'category', 'title', 'description', 'image_url']]]]);
    }

    public function test_profile_says_whether_the_member_accepts_chat_messages(): void
    {
        $member = User::factory()->approved()->create();
        $demo = User::factory()->approved()->create(['email' => 'demo1@'.DemoProgrammersSeeder::EMAIL_DOMAIN]);

        $this->getJson("/api/public/programmers/{$member->id}")->assertOk()->assertJsonPath('data.accepts_messages', true);
        $this->getJson("/api/public/programmers/{$demo->id}")->assertOk()->assertJsonPath('data.accepts_messages', false);
    }

    public function test_phone_email_and_workplace_follow_visibility_settings(): void
    {
        $hidden = User::factory()->approved()->create(['phone_visibility' => 'hidden', 'email_visibility' => 'hidden', 'workplace_visibility' => 'hidden']);
        $public = User::factory()->approved()->create(['phone_visibility' => 'public', 'email_visibility' => 'public', 'workplace_visibility' => 'public']);
        $membersOnly = User::factory()->approved()->create(['phone_visibility' => 'members', 'email_visibility' => 'members', 'workplace_visibility' => 'members']);

        $data = $this->getJson("/api/public/programmers/{$hidden->id}")->assertOk()->json('data');
        foreach (['phone', 'email', 'workplace'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        $this->getJson("/api/public/programmers/{$public->id}")->assertOk()
            ->assertJsonPath('data.phone', $public->phone)
            ->assertJsonPath('data.email', $public->email)
            ->assertJsonPath('data.workplace', $public->workplace);

        // "Members only": hidden from guests ...
        $data = $this->getJson("/api/public/programmers/{$membersOnly->id}")->assertOk()->json('data');
        foreach (['phone', 'email', 'workplace'] as $key) {
            $this->assertArrayNotHasKey($key, $data);
        }

        // ... and from a logged-in account that is not approved ...
        $pending = User::factory()->create();
        $data = $this->actingAs($pending, 'sanctum')->getJson("/api/public/programmers/{$membersOnly->id}")->assertOk()->json('data');
        $this->assertArrayNotHasKey('phone', $data);

        // ... but shown to a logged-in approved member.
        $viewer = User::factory()->approved()->create();
        $this->actingAs($viewer, 'sanctum')->getJson("/api/public/programmers/{$membersOnly->id}")->assertOk()
            ->assertJsonPath('data.phone', $membersOnly->phone)
            ->assertJsonPath('data.email', $membersOnly->email);
    }

    public function test_documents_payment_and_account_fields_are_never_present(): void
    {
        // Fixed texts, so random factory words cannot trip the checks below.
        $user = User::factory()->approved()->create([
            'name' => 'Karim', 'email' => 'karim@example.com', 'workplace' => 'Knit Ltd', 'bio' => 'Jacquard programmer',
            'phone_visibility' => 'public', 'email_visibility' => 'public',
        ]);
        $user->documents()->create(['type' => 'nid', 'original_name' => 'my-nid.pdf', 'path' => 'member-documents/x.pdf', 'mime_type' => 'application/pdf', 'size' => 100]);
        $this->assertSame(1, MemberDocument::count());

        foreach (["/api/public/programmers/{$user->id}", '/api/public/programmers'] as $url) {
            $body = $this->getJson($url)->assertOk()->getContent();
            foreach (['document', 'nid', 'my-nid', 'birth_certificate', 'download', 'role', '"status"', 'rejection', 'approved_by', 'approved_at', 'must_change_password', 'visibility', 'paid', 'due', 'payment', 'contribution', 'password', 'audit'] as $needle) {
                $this->assertStringNotContainsStringIgnoringCase($needle, $body, "{$url} contains {$needle}");
            }
        }
    }

    public function test_filters_lists_districts_of_directory_members_and_active_machines(): void
    {
        User::factory()->approved()->create(['district' => 'gazipur', 'machines' => ['stoll', 'shima-seiki']]);
        User::factory()->approved()->create(['district' => 'dhaka', 'machines' => ['stoll']]);
        User::factory()->create(['district' => 'khulna']); // pending
        User::factory()->suspended()->create(['district' => 'sylhet']);
        // Machines = the active items of the admin's list, in its order; hidden items are not offered.
        ProfileListItem::where('key', 'steiger')->update(['is_active' => false]);

        $this->getJson('/api/public/programmers/filters')->assertOk()
            ->assertExactJson(['data' => [
                'districts' => [
                    ['key' => 'dhaka', 'name_bn' => config('districts.dhaka.bn'), 'name_en' => 'Dhaka'],
                    ['key' => 'gazipur', 'name_bn' => config('districts.gazipur.bn'), 'name_en' => 'Gazipur'],
                ],
                'machines' => [
                    ['key' => 'stoll', 'name_bn' => 'STOLL', 'name_en' => 'STOLL'],
                    ['key' => 'shima-seiki', 'name_bn' => 'SHIMA SEIKI', 'name_en' => 'SHIMA SEIKI'],
                    ['key' => 'cixing', 'name_bn' => 'Cixing', 'name_en' => 'Cixing'],
                ],
            ]]);
    }

    public function test_list_query_count_does_not_grow_with_members(): void
    {
        $count = function (): int {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $this->getJson('/api/public/programmers')->assertOk();
            $queries = count(DB::getQueryLog());
            DB::disableQueryLog();

            return $queries;
        };

        $user = User::factory()->approved()->create();
        $this->addPortfolio($user, 'x', 1);
        $one = $count();

        foreach (User::factory()->count(5)->approved()->create() as $other) {
            $this->addPortfolio($other, 'y'.$other->id, 1);
        }
        $this->assertSame($one, $count());
    }

    public function test_public_endpoints_are_rate_limited_per_ip(): void
    {
        for ($i = 0; $i < 60; $i++) {
            $this->getJson('/api/public/programmers')->assertOk();
        }

        $this->getJson('/api/public/programmers/filters')
            ->assertStatus(429)
            ->assertJsonPath('message', __('messages.directory_rate_limited'));

        // Another IP still gets through.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])->getJson('/api/public/programmers')->assertOk();
    }
}
