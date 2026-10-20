<?php

/**
 * Editable site images (Site Content Manager part 2). Every image is resized/compressed on upload:
 *  - mode "cover": cropped from the centre to w:h (never enlarged); "fit": scaled down inside w x h (keeps shape).
 *  - format: webp | jpeg (social share image) | png.
 * The favicon is special: one square source makes every PWA / browser icon size (see App\Support\SiteImageProcessor).
 */
return [
    'logo' => ['group' => 'brand', 'w' => 600, 'h' => 600, 'mode' => 'fit', 'format' => 'webp', 'rec' => '512 × 512'],
    'favicon' => ['group' => 'brand', 'w' => 512, 'h' => 512, 'mode' => 'cover', 'format' => 'png', 'rec' => '512 × 512', 'min' => 192],
    'og_image' => ['group' => 'brand', 'w' => 1200, 'h' => 630, 'mode' => 'cover', 'format' => 'jpeg', 'rec' => '1200 × 630'],
    'default_avatar' => ['group' => 'brand', 'w' => 400, 'h' => 400, 'mode' => 'cover', 'format' => 'webp', 'rec' => '400 × 400'],
    'about_image' => ['group' => 'about', 'w' => 1200, 'h' => 900, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1200 × 900'],
    'about_banner' => ['group' => 'about', 'w' => 1600, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1600 × 500'],
    'header_programmers' => ['group' => 'headers', 'w' => 1920, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1920 × 500'],
    'header_gallery' => ['group' => 'headers', 'w' => 1920, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1920 × 500'],
    'header_notices' => ['group' => 'headers', 'w' => 1920, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1920 × 500'],
    'header_about' => ['group' => 'headers', 'w' => 1920, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1920 × 500'],
    'header_contact' => ['group' => 'headers', 'w' => 1920, 'h' => 500, 'mode' => 'cover', 'format' => 'webp', 'rec' => '1920 × 500'],
];
