<?php

namespace Database\Seeders;

use App\Models\PortfolioItem;
use App\Models\ProfileListItem;
use App\Models\User;
use App\Support\ProfileLists;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * 15 demo programmers for trying out the public directory (Phase 5b) — LOCAL TESTING ONLY.
 * Not called from DatabaseSeeder. Run:   php artisan db:seed --class=DemoProgrammersSeeder
 * Remove:                                php artisan jpphaf:remove-demo-programmers
 *
 * Every demo email ends with @demo.jpphaf.local — that is how the remove command finds them.
 * Running again refreshes the same 15 accounts (no duplicates). Images are generated SVG placeholders.
 * The accounts get a random password nobody knows (they are not meant to log in).
 */
class DemoProgrammersSeeder extends Seeder
{
    public const EMAIL_DOMAIN = 'demo.jpphaf.local';

    /** Demo mobile numbers: 01990000101 … 01990000115. */
    private const PHONE_PREFIX = '01990000';

    private const COLORS = ['#0fb3b0', '#0560a5', '#14b8a6', '#0b4f7a', '#06a3b5', '#2563eb', '#0d9488'];

    public function run(): void
    {
        if (app()->isProduction()) {
            $this->command?->warn('DemoProgrammersSeeder is for local testing only — skipped in production.');

            return;
        }

        $created = 0;
        foreach ($this->programmers() as $i => $p) {
            $n = $i + 1;
            $email = "demo{$n}@".self::EMAIL_DOMAIN;
            $phone = self::PHONE_PREFIX.(100 + $n);

            $user = User::where('email', $email)->first();
            if (! $user && User::where('phone', $phone)->exists()) {
                $this->command?->warn("Skipped {$p['name']}: mobile {$phone} already belongs to a real account.");

                continue;
            }

            $user ??= new User(['email' => $email, 'phone' => $phone, 'password' => Str::random(40)]);
            $user->fill([
                'name' => $p['name'],
                'district' => $p['district'],
                'workplace' => $p['workplace'],
                'experience_years' => $p['experience'],
                'employment_status' => $p['employment'],
                'designation' => $p['designation'],
                // Profiles store list keys (Phase 11d-1); missing names are added to the lists.
                'machines' => ProfileLists::keysForNames(ProfileListItem::TYPE_MACHINE, $p['machines']),
                'skills' => ProfileLists::keysForNames(ProfileListItem::TYPE_SKILL, $p['skills']),
                'bio' => $p['bio'],
                'phone_visibility' => $p['visibility'][0],
                'email_visibility' => $p['visibility'][1],
                'workplace_visibility' => $p['visibility'][2],
            ]);
            $user->forceFill([
                'role' => User::ROLE_MEMBER,
                'status' => User::STATUS_APPROVED,
                'approved_at' => now()->subDays($n * 3),
                'must_change_password' => false,
            ]);

            // Photo: every 4th programmer has none, to show the fallback avatar.
            if ($n % 4 !== 0) {
                $photo = "profile-photos/demo-{$n}.svg";
                Storage::disk('public')->put($photo, $this->avatarSvg($p['name'], self::COLORS[$n % count(self::COLORS)]));
                $user->forceFill(['photo_path' => $photo]);
            }
            $user->save();

            // Fresh work history + portfolio on every run.
            $user->workExperiences()->delete();
            foreach ($p['work'] as $order => [$company, $position, $start, $end]) {
                $user->workExperiences()->create([
                    'company' => $company, 'position' => $position, 'start_year' => $start, 'end_year' => $end, 'sort_order' => $order,
                ]);
            }

            foreach ($user->portfolioItems()->get() as $old) {
                Storage::disk(PortfolioItem::DISK)->delete($old->image_path);
                $old->delete();
            }
            foreach (array_slice($this->portfolioPool(), $n % 3, $p['portfolio']) as $order => [$category, $title, $description]) {
                $path = "portfolio/demo-{$n}-".($order + 1).'.svg';
                Storage::disk(PortfolioItem::DISK)->put($path, $this->portfolioSvg($category, $order + $n));
                $item = new PortfolioItem(['category' => $category, 'title' => $title, 'description' => $description, 'sort_order' => $order]);
                $item->user()->associate($user);
                $item->image_path = $path;
                $item->save();
            }

            $created++;
        }

        $this->command?->info("{$created} demo programmers ready (emails end with @".self::EMAIL_DOMAIN.').');
    }

    /**
     * visibility = [phone, email, workplace]; work = [company, position, start, end|null(present)].
     *
     * @return list<array<string, mixed>>
     */
    private function programmers(): array
    {
        $pub = User::VISIBILITY_PUBLIC;
        $mem = User::VISIBILITY_MEMBERS;
        $hid = User::VISIBILITY_HIDDEN;

        return [
            ['name' => 'Md. Rahim Uddin', 'district' => 'dhaka', 'workplace' => 'ABC Sweaters Ltd.', 'experience' => 8, 'employment' => 'running_job',
                'designation' => 'Computerized Flat Knitting Programmer', 'machines' => ['STOLL', 'SHIMA SEIKI'], 'skills' => ['M1PLUS', 'SDS-ONE', 'Jacquard'],
                'bio' => 'Specialist in STOLL jacquard and intarsia programming for export knitwear. Turns buyer artwork into efficient, production-ready knitting programs and works closely with sample, production and quality teams.',
                'visibility' => [$pub, $pub, $pub], 'portfolio' => 6,
                'work' => [['ABC Sweaters Ltd.', 'Senior Knitting Programmer', 2022, null], ['XYZ Knitwear Ltd.', 'Knitting Programmer', 2018, 2022], ['Sample Textile Ltd.', 'Junior Programmer', 2016, 2018]]],
            ['name' => 'Md. Arif Hossain', 'district' => 'gazipur', 'workplace' => 'Delta Knit Composite', 'experience' => 6, 'employment' => 'running_job',
                'designation' => 'Senior Knitting Programmer', 'machines' => ['SHIMA SEIKI'], 'skills' => ['SDS-ONE APEX', 'Sampling'],
                'bio' => 'Shima Seiki SDS-ONE expert with a strong sample development background.',
                'visibility' => [$mem, $hid, $pub], 'portfolio' => 3,
                'work' => [['Delta Knit Composite', 'Senior Knitting Programmer', 2021, null], ['Rose Knit Ltd.', 'Programmer', 2019, 2021]]],
            ['name' => 'Tanvir Ahmed', 'district' => 'narayanganj', 'workplace' => 'Northern Knitwear Ltd.', 'experience' => 15, 'employment' => 'running_job',
                'designation' => 'Jacquard Pattern Programmer', 'machines' => ['STOLL', 'Steiger'], 'skills' => ['M1PLUS', 'Multi-gauge'],
                'bio' => 'Leads programming for complex multi-gauge sweater production lines.',
                'visibility' => [$hid, $hid, $pub], 'portfolio' => 4,
                'work' => [['Northern Knitwear Ltd.', 'Head Programmer', 2017, null], ['Fakir Fashion Ltd.', 'Programmer', 2011, 2017]]],
            ['name' => 'Nusrat Jahan', 'district' => 'dhaka', 'workplace' => null, 'experience' => 4, 'employment' => 'jobless',
                'designation' => 'Sample Programmer', 'machines' => ['STOLL'], 'skills' => ['M1PLUS', 'Pattern correction'],
                'bio' => 'Fast, precise sample programmer skilled in M1PLUS and pattern correction. Open to new opportunities.',
                'visibility' => [$pub, $mem, $pub], 'portfolio' => 2,
                'work' => [['Hams Garments Ltd.', 'Sample Programmer', 2021, 2025]]],
            ['name' => 'Saiful Islam', 'district' => 'narayanganj', 'workplace' => 'Fakir Fashion Ltd.', 'experience' => 7, 'employment' => 'running_job',
                'designation' => 'Knitting Programmer', 'machines' => ['STOLL', 'Stoll CMS'], 'skills' => ['M1PLUS', 'Machine optimisation'],
                'bio' => 'Handles bulk production programs and machine optimisation on STOLL CMS.',
                'visibility' => [$mem, $hid, $mem], 'portfolio' => 3,
                'work' => [['Fakir Fashion Ltd.', 'Knitting Programmer', 2019, null], ['Square Fashions Ltd.', 'Assistant Programmer', 2017, 2019]]],
            ['name' => 'Rakib Hasan', 'district' => 'dhaka', 'workplace' => null, 'experience' => 2, 'employment' => 'jobless',
                'designation' => 'Junior Knitting Programmer', 'machines' => ['SHIMA SEIKI'], 'skills' => ['SDS-ONE'],
                'bio' => 'Recently trained on Shima Seiki, eager to grow in a production team.',
                'visibility' => [$pub, $hid, $pub], 'portfolio' => 0,
                'work' => []],
            ['name' => 'Imran Hossain', 'district' => 'chattogram', 'workplace' => 'Epyllion Knitwears', 'experience' => 12, 'employment' => 'running_job',
                'designation' => 'Head of Programming', 'machines' => ['STOLL', 'SHIMA SEIKI', 'Cixing'], 'skills' => ['M1PLUS', 'SDS-ONE', 'Team leading'],
                'bio' => 'Manages a team of 14 programmers across fully-fashioned knitwear.',
                'visibility' => [$hid, $pub, $pub], 'portfolio' => 5,
                'work' => [['Epyllion Knitwears', 'Head of Programming', 2018, null], ['Viyellatex Group', 'Senior Programmer', 2013, 2018]]],
            ['name' => 'Sharmin Akter', 'district' => 'gazipur', 'workplace' => 'Viyellatex Group', 'experience' => 9, 'employment' => 'running_job',
                'designation' => 'Pattern Programmer', 'machines' => ['SHIMA SEIKI'], 'skills' => ['SDS-ONE APEX', 'Jacquard artwork'],
                'bio' => 'Known for clean jacquard artwork conversion and yarn-efficient programs.',
                'visibility' => [$mem, $mem, $hid], 'portfolio' => 4,
                'work' => [['Viyellatex Group', 'Pattern Programmer', 2020, null], ['Delta Knit Composite', 'Programmer', 2016, 2020]]],
            ['name' => 'Mahmudul Hasan', 'district' => 'dhaka', 'workplace' => null, 'experience' => 5, 'employment' => 'jobless',
                'designation' => 'Knitting Programmer', 'machines' => ['STOLL'], 'skills' => ['M1PLUS', 'Costing'],
                'bio' => 'Experienced in sweater costing support and STOLL M1PLUS programming.',
                'visibility' => [$pub, $pub, $pub], 'portfolio' => 2,
                'work' => [['Northern Knitwear Ltd.', 'Knitting Programmer', 2020, 2025]]],
            ['name' => 'Kamrul Islam', 'district' => 'mymensingh', 'workplace' => 'Square Fashions Ltd.', 'experience' => 11, 'employment' => 'running_job',
                'designation' => 'Senior Knitting Programmer', 'machines' => ['STOLL', 'SHIMA SEIKI'], 'skills' => ['M1PLUS', 'Training'],
                'bio' => 'Trains new programmers and maintains the company pattern library.',
                'visibility' => [$mem, $hid, $pub], 'portfolio' => 3,
                'work' => [['Square Fashions Ltd.', 'Senior Knitting Programmer', 2016, null], ['ABC Sweaters Ltd.', 'Programmer', 2014, 2016]]],
            ['name' => 'Habibur Rahman', 'district' => 'gazipur', 'workplace' => 'Hams Garments Ltd.', 'experience' => 3, 'employment' => 'running_job',
                'designation' => 'Machine Programmer', 'machines' => ['Cixing'], 'skills' => ['12GG / 14GG programs'],
                'bio' => 'Focused on 12GG and 14GG fine-gauge programs for European buyers.',
                'visibility' => [$hid, $hid, $pub], 'portfolio' => 1,
                'work' => [['Hams Garments Ltd.', 'Machine Programmer', 2023, null]]],
            ['name' => 'Farzana Yasmin', 'district' => 'dhaka', 'workplace' => null, 'experience' => 20, 'employment' => 'jobless',
                'designation' => 'Jacquard Programmer', 'machines' => ['STOLL', 'SHIMA SEIKI'], 'skills' => ['M1PLUS', 'SDS-ONE', 'Intarsia'],
                'bio' => 'Two decades of knitwear programming across STOLL and Shima Seiki.',
                'visibility' => [$mem, $pub, $pub], 'portfolio' => 6,
                'work' => [['Epyllion Knitwears', 'Senior Jacquard Programmer', 2012, 2025], ['Rose Knit Ltd.', 'Jacquard Programmer', 2005, 2012]]],
            ['name' => 'Shafiqul Alam', 'district' => 'cumilla', 'workplace' => 'Rose Knit Ltd.', 'experience' => 0, 'employment' => 'running_job',
                'designation' => 'Trainee Programmer', 'machines' => ['STOLL'], 'skills' => ['M1PLUS basics'],
                'bio' => null,
                'visibility' => [$hid, $hid, $hid], 'portfolio' => 0,
                'work' => [['Rose Knit Ltd.', 'Trainee Programmer', 2026, null]]],
            ['name' => 'Jahid Hasan', 'district' => 'narsingdi', 'workplace' => 'Mondol Knitwears', 'experience' => 10, 'employment' => 'running_job',
                'designation' => 'Production Programmer', 'machines' => ['Steiger', 'STOLL'], 'skills' => ['Production planning'],
                'bio' => 'Keeps 60 machines running with tuned production programs.',
                'visibility' => [$pub, $hid, $pub], 'portfolio' => 2,
                'work' => [['Mondol Knitwears', 'Production Programmer', 2016, null]]],
            ['name' => 'Sumaiya Khatun', 'district' => 'tangail', 'workplace' => null, 'experience' => 1, 'employment' => null,
                'designation' => null, 'machines' => ['SHIMA SEIKI'], 'skills' => ['SDS-ONE'],
                'bio' => 'Finished a 6-month Shima Seiki programming course.',
                'visibility' => [$mem, $hid, $pub], 'portfolio' => 1,
                'work' => []],
        ];
    }

    /** [category, title, description] — categories from config/profile_options.php. */
    private function portfolioPool(): array
    {
        return [
            ['jacquard', 'Nordic Jacquard Collection', 'Three-colour snowflake jacquard for a European winter line.'],
            ['programming', 'Pattern Library Rebuild', 'Converted 200+ legacy patterns to optimised modern programs.'],
            ['production', '12GG Fine Knit Line', 'Set up programs for a 40-machine fine-gauge bulk order.'],
            ['optimisation', 'Line Efficiency Project', 'Cut knitting time per panel by 14% through program tuning.'],
            ['training', 'Junior Programmer Training', 'Ran a 6-week training course for new team members.'],
            ['sampling', 'Buyer Sample Showcase', 'Delivered 35 approved samples for a seasonal buyer review.'],
            ['intarsia', 'Intarsia Logo Sweater', null],
            ['other', 'Machine Setup Guide', 'Step-by-step setup notes for new machines.'],
        ];
    }

    /** Placeholder profile photo: coloured square with a person shape and initials. */
    private function avatarSvg(string $name, string $color): string
    {
        $initials = collect(preg_split('/\s+/', str_replace('Md.', '', $name)))
            ->filter()->map(fn (string $w) => mb_substr($w, 0, 1))->take(2)->implode('');

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="400" height="400" viewBox="0 0 400 400">
          <rect width="400" height="400" fill="{$color}"/>
          <circle cx="200" cy="160" r="72" fill="#ffffff" fill-opacity="0.25"/>
          <path d="M70 400c0-80 58-130 130-130s130 50 130 130z" fill="#ffffff" fill-opacity="0.25"/>
          <text x="200" y="178" text-anchor="middle" font-family="Arial, sans-serif" font-size="64" font-weight="700" fill="#ffffff">{$initials}</text>
        </svg>
        SVG;
    }

    /** Placeholder portfolio image: gradient with a jacquard-like diamond pattern and the category name. */
    private function portfolioSvg(string $category, int $seed): string
    {
        $from = self::COLORS[$seed % count(self::COLORS)];
        $to = self::COLORS[($seed + 3) % count(self::COLORS)];
        $label = strtoupper($category);

        return <<<SVG
        <svg xmlns="http://www.w3.org/2000/svg" width="800" height="600" viewBox="0 0 800 600">
          <defs>
            <linearGradient id="g" x1="0" y1="0" x2="1" y2="1">
              <stop offset="0" stop-color="{$from}"/>
              <stop offset="1" stop-color="{$to}"/>
            </linearGradient>
            <pattern id="d" width="60" height="60" patternUnits="userSpaceOnUse">
              <path d="M30 4 L56 30 L30 56 L4 30 Z" fill="none" stroke="#ffffff" stroke-opacity="0.22" stroke-width="3"/>
              <circle cx="30" cy="30" r="5" fill="#ffffff" fill-opacity="0.25"/>
            </pattern>
          </defs>
          <rect width="800" height="600" fill="url(#g)"/>
          <rect width="800" height="600" fill="url(#d)"/>
          <text x="400" y="315" text-anchor="middle" font-family="Arial, sans-serif" font-size="44" font-weight="700" letter-spacing="6" fill="#ffffff" fill-opacity="0.9">{$label}</text>
        </svg>
        SVG;
    }
}
