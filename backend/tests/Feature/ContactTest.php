<?php

namespace Tests\Feature;

use App\Models\ContactMessage;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Tests\TestCase;

class ContactTest extends TestCase
{
    use RefreshDatabase;

    private function validData(array $overrides = []): array
    {
        return array_merge([
            'name' => 'রহিম উদ্দিন',
            'phone' => '01712345678',
            'email' => 'rahim@example.com',
            'subject' => 'সদস্যপদ',
            'message' => 'আমি সদস্য হতে চাই।',
        ], $overrides);
    }

    public function test_saves_message_and_returns_success(): void
    {
        $this->postJson('/api/contact', $this->validData(), ['Accept-Language' => 'en'])
            ->assertCreated()
            ->assertJsonPath('message', __('messages.contact_sent', [], 'en'));

        $this->assertDatabaseHas('contact_messages', [
            'name' => 'রহিম উদ্দিন',
            'phone' => '01712345678',
            'email' => 'rahim@example.com',
            'is_read' => false,
            'ip_address' => '127.0.0.1',
        ]);
    }

    public function test_email_is_optional(): void
    {
        $this->postJson('/api/contact', $this->validData(['email' => null]))->assertCreated();
        $this->postJson('/api/contact', $this->validData(['email' => '']))->assertCreated();

        $this->assertSame(2, ContactMessage::count());
    }

    public function test_required_fields(): void
    {
        $this->postJson('/api/contact', [])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'phone', 'subject', 'message'])
            ->assertJsonMissingValidationErrors(['email']);

        $this->assertSame(0, ContactMessage::count());
    }

    public function test_rejects_invalid_values(): void
    {
        $this->postJson('/api/contact', $this->validData([
            'name' => str_repeat('a', 101),
            'email' => 'not-an-email',
            'subject' => str_repeat('a', 151),
            'message' => str_repeat('a', 2001),
        ]))->assertUnprocessable()
            ->assertJsonValidationErrors(['name', 'email', 'subject', 'message']);
    }

    public function test_phone_must_be_bangladeshi_mobile(): void
    {
        $this->withoutMiddleware(ThrottleRequests::class); // more than 5 requests here

        foreach (['0171234567', '017123456789', '+8801712345678', '02712345678', '01212345678', '0171234567a'] as $bad) {
            $this->postJson('/api/contact', $this->validData(['phone' => $bad]))
                ->assertUnprocessable()
                ->assertJsonValidationErrors(['phone']);
        }

        foreach (['01312345678', '01912345678'] as $good) {
            $this->postJson('/api/contact', $this->validData(['phone' => $good]))->assertCreated();
        }
    }

    public function test_errors_are_bengali_by_default_and_english_on_request(): void
    {
        // Note: the test client sends "Accept-Language: en-us" unless told otherwise.
        // An unsupported language falls back to Bengali.
        $this->postJson('/api/contact', $this->validData(['name' => '', 'phone' => '123']), ['Accept-Language' => 'fr'])
            ->assertJsonPath('message', 'নাম দেওয়া আবশ্যক। (এবং আরও 1 টি ত্রুটি)')
            ->assertJsonPath('errors.name.0', 'নাম দেওয়া আবশ্যক।')
            ->assertJsonPath('errors.phone.0', 'সঠিক বাংলাদেশি মোবাইল নম্বর দিন (১১ অঙ্ক, যেমন 01712345678)।');

        $this->postJson('/api/contact', $this->validData(['name' => '']), ['Accept-Language' => 'bn-BD,bn;q=0.9'])
            ->assertJsonPath('errors.name.0', 'নাম দেওয়া আবশ্যক।');

        $this->postJson('/api/contact', $this->validData(['name' => '', 'phone' => '123']), ['Accept-Language' => 'en-US,en;q=0.9'])
            ->assertJsonPath('errors.name.0', 'The name field is required.')
            ->assertJsonPath('errors.phone.0', 'Enter a valid Bangladeshi mobile number (11 digits, e.g. 01712345678).');
    }

    public function test_rate_limited_to_five_per_hour_per_ip(): void
    {
        for ($i = 0; $i < 5; $i++) {
            $this->postJson('/api/contact', $this->validData())->assertCreated();
        }

        $this->postJson('/api/contact', $this->validData(), ['Accept-Language' => 'bn'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.contact_rate_limited', [], 'bn'));

        $this->postJson('/api/contact', $this->validData(), ['Accept-Language' => 'en'])
            ->assertTooManyRequests()
            ->assertJsonPath('message', __('messages.contact_rate_limited', [], 'en'));

        // Another IP is not affected.
        $this->withServerVariables(['REMOTE_ADDR' => '10.0.0.2'])
            ->postJson('/api/contact', $this->validData())
            ->assertCreated();

        $this->assertSame(6, ContactMessage::count());

        // After an hour the same IP can send again.
        $this->travel(61)->minutes();
        $this->postJson('/api/contact', $this->validData())->assertCreated();
    }
}
