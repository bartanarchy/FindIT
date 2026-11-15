<?php

require_once dirname(__DIR__) . '/app/helpers/captcha.php';

// tell browser that the content is an image
header('Content-Type: image/png');

header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

// make random code
$code = generateCaptchaCode();

$small = imagecreatetruecolor(100, 30);

$background = imagecolorallocate($small, 246, 243, 236);
imagefilledrectangle($small, 0, 0, 99, 29, $background);

// draw the letters 
for ($i = 0; $i < strlen($code); $i++) {
    $color = imagecolorallocate(
        $small,
        random_int(20, 90),
        random_int(40, 100),
        random_int(20, 90)
    );

    imagestring($small, 5, 8 + ($i * 17), random_int(3, 9), $code[$i], $color);
}

$image = imagecreatetruecolor(200, 60);
imagecopyresampled($image, $small, 0, 0, 0, 0, 200, 60, 100, 30);

// noise for the image
$noise = imagecolorallocate($image, 150, 170, 120);

for ($i = 0; $i < 5; $i++) {
    imageline(
        $image,
        random_int(0, 199), random_int(0, 59),
        random_int(0, 199), random_int(0, 59),
        $noise
    );
}

for ($i = 0; $i < 120; $i++) {
    imagesetpixel($image, random_int(0, 199), random_int(0, 59), $noise);
}

// Ksend the image
imagepng($image);   