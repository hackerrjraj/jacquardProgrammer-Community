<?php

namespace Database\Seeders;

use App\Models\HeroSlide;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;

class HeroSlideSeeder extends Seeder
{
    /**
     * Three sample hero slides. Images are simple gradient SVGs generated here
     * (no downloads) into storage/app/public/slides. Admin replaces them in Phase 11.
     */
    public function run(): void
    {
        $slides = [
            [
                'file' => 'slide-1.svg',
                'colors' => ['#0f766e', '#1e3a5f'],
                'title_bn' => 'জ্যাকার্ড প্রোগ্রামারদের পাশে, একসাথে',
                'title_en' => 'Standing together for Jacquard programmers',
                'subtitle_bn' => 'কম্পিউটারাইজড ফ্ল্যাট নিটিং ও জ্যাকার্ড প্রোগ্রামারদের মানবিক সহায়তা তহবিল',
                'subtitle_en' => 'A humanitarian assistance fund for Computerized Flat Knitting and Jacquard programmers',
                'button_text_bn' => 'সদস্য হোন',
                'button_text_en' => 'Become a member',
                'button_link' => '/register',
            ],
            [
                'file' => 'slide-2.svg',
                'colors' => ['#1e3a5f', '#115e59'],
                'title_bn' => 'স্বচ্ছ তহবিল, স্পষ্ট হিসাব',
                'title_en' => 'A transparent fund with clear accounts',
                'subtitle_bn' => 'প্রতিটি জমা ও সহায়তার হিসাব সবার জন্য উন্মুক্ত',
                'subtitle_en' => 'Every contribution and every payout is open for everyone to see',
                'button_text_bn' => 'হিসাব দেখুন',
                'button_text_en' => 'See the report',
                'button_link' => '/fund-report',
            ],
            [
                'file' => 'slide-3.svg',
                'colors' => ['#115e59', '#b45309'],
                'title_bn' => 'দক্ষ প্রোগ্রামারদের খুঁজুন',
                'title_en' => 'Find skilled programmers',
                'subtitle_bn' => 'অভিজ্ঞতা ও মেশিন অনুযায়ী সদস্যদের তালিকা',
                'subtitle_en' => 'Browse members by experience and machine',
                'button_text_bn' => null,
                'button_text_en' => null,
                'button_link' => null,
            ],
        ];

        foreach ($slides as $order => $slide) {
            $path = 'slides/'.$slide['file'];
            Storage::disk('public')->put($path, $this->placeholderSvg(...$slide['colors']));

            HeroSlide::updateOrCreate(
                ['image_path' => $path],
                [
                    'title_bn' => $slide['title_bn'],
                    'title_en' => $slide['title_en'],
                    'subtitle_bn' => $slide['subtitle_bn'],
                    'subtitle_en' => $slide['subtitle_en'],
                    'button_text_bn' => $slide['button_text_bn'],
                    'button_text_en' => $slide['button_text_en'],
                    'button_link' => $slide['button_link'],
                    'sort_order' => $order + 1,
                    'is_active' => true,
                ],
            );
        }
    }

    /** 1920x800 diagonal gradient with a faint knit-stitch (zig-zag) pattern. */
    private function placeholderSvg(string $from, string $to): string
    {
        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="1920" height="800" viewBox="0 0 1920 800">
          <defs>
            <linearGradient id="bg" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="{$from}"/>
              <stop offset="1" stop-color="{$to}"/>
            </linearGradient>
            <pattern id="knit" width="48" height="32" patternUnits="userSpaceOnUse">
              <path d="M0 8 L12 24 L24 8 L36 24 L48 8" fill="none" stroke="#ffffff" stroke-opacity="0.08" stroke-width="3"/>
            </pattern>
          </defs>
          <rect width="1920" height="800" fill="url(#bg)"/>
          <rect width="1920" height="800" fill="url(#knit)"/>
          <circle cx="1600" cy="160" r="260" fill="#ffffff" fill-opacity="0.05"/>
          <circle cx="260" cy="700" r="200" fill="#000000" fill-opacity="0.08"/>
        </svg>
        SVG;
    }
}
